<?php

declare(strict_types=1);

namespace App\Actions\Admin\AdBilling;

use App\Models\AdInvoice;
use App\Models\AdRateCard;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5 — an invoice's per-branch delivery breakdown (the statement
 * detail an advertiser can be shown). DERIVED from the impressions the
 * invoice period covers. Impressions are append-only and the period is
 * in the past, so the derivation is stable.
 *
 * ATTRIBUTION RULE: the invoice total counts each (device, UTC day) pair
 * ONCE, but branch_id is snapshotted per impression row — a device
 * reassigned to another branch mid-day would appear under BOTH branches
 * if screen-days were counted per branch, making the lines sum above the
 * invoice. Each (device, day) is therefore attributed to the branch with
 * the MOST plays that day (tie → lowest branch id), so per-screen-day
 * lines always sum exactly to the invoice amount. Plays and seconds
 * partition per row naturally.
 */
final class AdInvoiceLinesAction
{
    /**
     * @return list<array<string, mixed>>
     */
    public function handle(AdInvoice $invoice): array
    {
        // One row per (branch, device, UTC day) cell; the attribution and
        // the per-branch totals are folded in PHP — driver-portable.
        $cells = DB::table('pos_marketing_impressions')
            ->leftJoin('pos_branches', 'pos_branches.id', '=', 'pos_marketing_impressions.branch_id')
            ->where('pos_marketing_impressions.advertiser_id', $invoice->advertiser_id)
            ->whereBetween('pos_marketing_impressions.played_at', [
                $invoice->period_from->copy()->startOfDay(),
                $invoice->period_to->copy()->endOfDay(),
            ])
            ->selectRaw('
                pos_marketing_impressions.branch_id AS branch_id,
                MAX(pos_branches.name) AS branch_name,
                COALESCE(pos_marketing_impressions.device_id, 0) AS device_id,
                DATE(pos_marketing_impressions.played_at) AS play_day,
                COUNT(*) AS plays,
                COALESCE(SUM(pos_marketing_impressions.play_duration_ms), 0) AS ms
            ')
            ->groupBy('pos_marketing_impressions.branch_id', 'pos_marketing_impressions.device_id', DB::raw('DATE(pos_marketing_impressions.played_at)'))
            ->get();

        /** @var array<int|string, array{branch_id: int|null, branch_name: string, plays: int, ms: int, screen_days: int}> $byBranch */
        $byBranch = [];
        /** @var array<string, array{branch: int|string, plays: int}> $dayWinner */
        $dayWinner = [];

        foreach ($cells as $cell) {
            $branchKey = $cell->branch_id !== null ? (int) $cell->branch_id : 'none';
            $byBranch[$branchKey] ??= [
                'branch_id' => $cell->branch_id !== null ? (int) $cell->branch_id : null,
                'branch_name' => (string) ($cell->branch_name ?? '—'),
                'plays' => 0, 'ms' => 0, 'screen_days' => 0,
            ];
            $byBranch[$branchKey]['plays'] += (int) $cell->plays;
            $byBranch[$branchKey]['ms'] += (int) $cell->ms;

            // Screen-day attribution: most plays wins; tie → lowest branch id
            // ('none' loses to any real branch).
            $pairKey = $cell->device_id.'@'.$cell->play_day;
            $current = $dayWinner[$pairKey] ?? null;
            $beats = $current === null
                || (int) $cell->plays > $current['plays']
                || ((int) $cell->plays === $current['plays']
                    && is_int($branchKey)
                    && (! is_int($current['branch']) || $branchKey < $current['branch']));
            if ($beats) {
                $dayWinner[$pairKey] = ['branch' => $branchKey, 'plays' => (int) $cell->plays];
            }
        }
        foreach ($dayWinner as $winner) {
            $byBranch[$winner['branch']]['screen_days']++;
        }

        $rateBaisas = (int) round((float) $invoice->rate * 1000);
        $lines = [];
        foreach ($byBranch as $b) {
            $amountBaisas = $invoice->pricing_model === AdRateCard::MODEL_PER_DAY_PER_SCREEN
                ? $rateBaisas * $b['screen_days']
                : intdiv($rateBaisas * $b['plays'], 1000);

            $lines[] = [
                'branch_id' => $b['branch_id'],
                'branch_name' => $b['branch_name'],
                'plays' => $b['plays'],
                'play_seconds' => intdiv($b['ms'], 1000),
                'screen_days' => $b['screen_days'],
                'amount' => number_format($amountBaisas / 1000, 3, '.', ''),
            ];
        }
        usort($lines, static fn (array $x, array $y): int => (float) $y['amount'] <=> (float) $x['amount']);

        return $lines;
    }
}
