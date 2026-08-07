<?php

declare(strict_types=1);

namespace App\Actions\Admin\AdBilling;

use App\Models\Advertiser;
use App\Models\AdInvoice;
use App\Models\AdRateCard;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase 5 — issue an advertiser's invoice for a period, priced from
 * their ACTIVE rate card and metered from pos_marketing_impressions
 * (delivery-based: what actually PLAYED, not what was scheduled).
 *
 *   per_thousand_impressions — amount = rate × plays ÷ 1000
 *   per_day_per_screen       — amount = rate × COUNT DISTINCT
 *                              (device, calendar day) the content played on
 *
 * TRUST MODEL — read before relying on these figures. The impressions ledger
 * is replay-guarded at ingest (UNIQUE device + client_event_id), but that
 * guard is keyed on a device-chosen id: fresh uuids satisfy it, so it stops
 * accidental double-counting, NOT a device reporting plays that never
 * happened. This docblock used to claim the count "can only ever UNDER-state
 * delivery — an invoice can never over-bill", and that was false; it was also
 * the stated reason nothing here bounds the numbers.
 *
 * What actually bounds them now lives at ingest (pos_api SliderDisplayHandler):
 * played_at is UTC-normalised and pinned to server receipt time, the campaign
 * must have been live on that device at play time, and a per-device daily play
 * ceiling caps the CPM count. The figures are therefore as trustworthy as the
 * fleet is un-tampered-with — good enough to bill on, not a cryptographic
 * guarantee. Anomalous volume is worth eyeballing before issuing at scale.
 *
 * Everything is SNAPSHOT onto the invoice (model, rate, quantities) so
 * later rate edits or data changes never move an issued bill. Duplicate
 * guard: refuses when any non-void invoice overlaps the period; the
 * rate-card row lock (see below) is what serialises concurrent issuance.
 *
 * DAY SEMANTICS: played_at is UTC end-to-end (devices convert before
 * sending; both apps run UTC), so a "screen-day" and the period bounds
 * are UTC calendar days — Muscat is UTC+4, so the day boundary falls at
 * 04:00 local. Consistent and unambiguous; revisit only if invoices must
 * ever quote Muscat-local days.
 */
final class CreateAdInvoiceAction
{
    public function handle(int $advertiserId, CarbonInterface $from, CarbonInterface $to, ?int $actorId, ?string $note = null): AdInvoice
    {
        if ($to->lessThan($from)) {
            throw new RuntimeException('The period end must not be before its start.');
        }

        return DB::transaction(function () use ($advertiserId, $from, $to, $actorId, $note): AdInvoice {
            if (! Advertiser::withTrashed()->whereKey($advertiserId)->exists()) {
                throw new RuntimeException('Unknown advertiser.');
            }

            // lockForUpdate on the rate card is the SERIALISATION POINT for
            // concurrent issuance: FOR UPDATE on the overlap guard below
            // locks nothing when no invoice exists yet (Postgres takes no
            // gap locks at READ COMMITTED), so two simultaneous creates
            // could both pass it. The active card row always exists for a
            // billable advertiser and is per-advertiser — the second
            // transaction blocks here until the first commits, then its
            // overlap guard sees the new invoice and throws.
            $card = AdRateCard::query()
                ->where('advertiser_id', $advertiserId)
                ->where('is_active', true)
                ->latest('id')
                ->lockForUpdate()
                ->first();
            if ($card === null) {
                throw new RuntimeException('This advertiser has no active rate card — set one before invoicing.');
            }

            // Duplicate guard: no second live bill may overlap the period.
            $overlap = AdInvoice::query()
                ->where('advertiser_id', $advertiserId)
                ->where('status', '<>', AdInvoice::STATUS_VOID)
                ->whereDate('period_from', '<=', $to)
                ->whereDate('period_to', '>=', $from)
                ->lockForUpdate()
                ->exists();
            if ($overlap) {
                throw new RuntimeException('An invoice already covers (part of) this period. Void it first to re-issue.');
            }

            // Meter the period's delivery; the period is inclusive of both
            // dates. played_at is device-REPORTED (a play may legitimately
            // predate its sync), clamped at ingest into a believable window
            // and frozen against replay — see pos_api's SliderDisplayHandler.
            // It is not server-stamped, and a comment here once claimed it was.
            $meter = DB::table('pos_marketing_impressions')
                ->where('advertiser_id', $advertiserId)
                ->whereBetween('played_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                // Driver-portable (sqlite tests + Postgres prod): || concat
                // and DATE() work in both; COALESCE guards a NULL device_id.
                ->selectRaw("
                    COUNT(*) AS plays,
                    COALESCE(SUM(play_duration_ms), 0) AS ms,
                    COUNT(DISTINCT (COALESCE(device_id, 0) || '@' || DATE(played_at))) AS screen_days
                ")
                ->first();

            $plays = (int) ($meter->plays ?? 0);
            $screenDays = (int) ($meter->screen_days ?? 0);
            if ($plays === 0) {
                throw new RuntimeException('Nothing was delivered for this advertiser in the selected period.');
            }

            // Exact INTEGER-BAISAS math, rounded DOWN — the "never over-bill"
            // guarantee must hold at the arithmetic level too, not just at
            // the metering level (floats + half-up rounding could bill half
            // a baisa above delivered value). rate is decimal(12,3), so
            // rate-in-baisas is exact.
            $rateBaisas = (int) round((float) $card->rate * 1000);
            $amountBaisas = $card->pricing_model === AdRateCard::MODEL_PER_DAY_PER_SCREEN
                ? $rateBaisas * $screenDays
                : intdiv($rateBaisas * $plays, 1000);

            return AdInvoice::query()->create([
                'uuid' => (string) Str::uuid(),
                'advertiser_id' => $advertiserId,
                'period_from' => $from->toDateString(),
                'period_to' => $to->toDateString(),
                'status' => AdInvoice::STATUS_ISSUED,
                'pricing_model' => $card->pricing_model,
                'rate' => number_format($rateBaisas / 1000, 3, '.', ''),
                'impressions_count' => $plays,
                'play_seconds' => intdiv((int) ($meter->ms ?? 0), 1000),
                'screen_days' => $screenDays,
                'amount' => number_format($amountBaisas / 1000, 3, '.', ''),
                'note' => $note,
                'issued_by_user_id' => $actorId,
            ]);
        });
    }
}
