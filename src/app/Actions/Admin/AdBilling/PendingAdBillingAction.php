<?php

declare(strict_types=1);

namespace App\Actions\Admin\AdBilling;

use App\Models\AdInvoice;
use App\Models\AdRateCard;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5 — the "ads to bill" drill: advertisers whose content DELIVERED
 * in the window, with the metered quantities and an estimate from their
 * active rate card (null when no card is set — the operator's cue to set
 * one). has_overlapping_invoice flags a window already (partly) billed —
 * CreateAdInvoiceAction refuses those outright; the flag saves the
 * operator the failed attempt.
 */
final class PendingAdBillingAction
{
    /**
     * @return list<array<string, mixed>>
     */
    public function handle(CarbonInterface $from, CarbonInterface $to): array
    {
        $meters = DB::table('pos_marketing_impressions')
            ->whereNotNull('advertiser_id')
            ->whereBetween('played_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw("
                advertiser_id,
                COUNT(*) AS plays,
                COALESCE(SUM(play_duration_ms), 0) AS ms,
                COUNT(DISTINCT (COALESCE(device_id, 0) || '@' || DATE(played_at))) AS screen_days
            ")
            ->groupBy('advertiser_id')
            ->get();

        if ($meters->isEmpty()) {
            return [];
        }

        $advertiserIds = $meters->pluck('advertiser_id')->map(fn ($v) => (int) $v)->all();

        $advertisers = DB::table('advertisers')
            ->whereIn('id', $advertiserIds)
            ->get(['id', 'name', 'brand_name', 'status'])
            ->keyBy('id');

        $cards = AdRateCard::query()
            ->whereIn('advertiser_id', $advertiserIds)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->keyBy('advertiser_id');

        $overlapping = AdInvoice::query()
            ->whereIn('advertiser_id', $advertiserIds)
            ->where('status', '<>', AdInvoice::STATUS_VOID)
            ->whereDate('period_from', '<=', $to)
            ->whereDate('period_to', '>=', $from)
            ->pluck('advertiser_id')
            ->map(fn ($v) => (int) $v)
            ->flip();

        $out = [];
        foreach ($meters as $m) {
            $aid = (int) $m->advertiser_id;
            $advertiser = $advertisers->get($aid);
            if ($advertiser === null) {
                continue; // orphaned snapshot id — nothing to bill a ghost
            }
            $card = $cards->get($aid);
            $estimate = null;
            if ($card !== null) {
                $estimate = $card->pricing_model === AdRateCard::MODEL_PER_DAY_PER_SCREEN
                    ? (float) $card->rate * (int) $m->screen_days
                    : (float) $card->rate * (int) $m->plays / 1000;
            }

            $out[] = [
                'advertiser_id' => $aid,
                'advertiser_name' => (string) $advertiser->name,
                'brand_name' => (string) ($advertiser->brand_name ?? ''),
                'plays' => (int) $m->plays,
                'play_seconds' => intdiv((int) $m->ms, 1000),
                'screen_days' => (int) $m->screen_days,
                'pricing_model' => $card?->pricing_model,
                'rate' => $card !== null ? (string) $card->rate : null,
                'estimated_amount' => $estimate !== null ? number_format($estimate, 3, '.', '') : null,
                'has_overlapping_invoice' => isset($overlapping[$aid]),
            ];
        }
        usort($out, static fn (array $x, array $y): int => (float) ($y['estimated_amount'] ?? 0) <=> (float) ($x['estimated_amount'] ?? 0));

        return $out;
    }
}
