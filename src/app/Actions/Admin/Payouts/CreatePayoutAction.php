<?php

declare(strict_types=1);

namespace App\Actions\Admin\Payouts;

use App\Models\Payout;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v2 #17 (Phase B) — create a pending payout for a merchant + period.
 *
 * Claims the period's still-UNSETTLED merchant-commission rows (party_type=
 * 'merchant', payout_id NULL) by stamping payout_id on them, so the same
 * earnings can never be paid twice. net_amount = Σ those rows; the deduction
 * breakdown (gross/platform/bank/other) is snapshot from the SAME orders' full
 * party rows, so the statement is self-contained. Throws if there's nothing
 * unsettled in the window.
 */
final class CreatePayoutAction
{
    public function handle(int $companyId, CarbonInterface $from, CarbonInterface $to, ?int $actorId): Payout
    {
        return DB::transaction(function () use ($companyId, $from, $to, $actorId): Payout {
            // Unsettled merchant rows in the window (lock so a concurrent payout
            // can't claim the same rows).
            $merchantRows = DB::table('pos_sale_commissions')
                ->where('company_id', $companyId)
                ->whereBetween('occurred_at', [$from, $to])
                ->where('party_type', 'merchant')
                ->whereNull('payout_id')
                ->lockForUpdate()
                ->get(['id', 'order_id', 'commission_amount']);

            if ($merchantRows->isEmpty()) {
                throw new RuntimeException('No unsettled earnings for this merchant in the selected period.');
            }

            $rowIds = $merchantRows->pluck('id')->all();
            $orderIds = $merchantRows->pluck('order_id')->unique()->values()->all();
            $net = (float) $merchantRows->sum(static fn ($r): float => (float) $r->commission_amount);

            // Deduction snapshot from every party row of the settled orders.
            $byParty = DB::table('pos_sale_commissions')
                ->whereIn('order_id', $orderIds)
                ->selectRaw('party_type, COALESCE(SUM(commission_amount), 0) AS total')
                ->groupBy('party_type')
                ->pluck('total', 'party_type');
            $amt = static fn (string $p): float => (float) ($byParty[$p] ?? 0);
            $gross = $amt('platform') + $amt('bank') + $amt('other') + $amt('merchant');

            $payout = Payout::query()->create([
                'company_id' => $companyId,
                'period_from' => $from,
                'period_to' => $to,
                'status' => Payout::STATUS_PENDING,
                'gross_amount' => self::fmt($gross),
                'platform_amount' => self::fmt($amt('platform')),
                'bank_amount' => self::fmt($amt('bank')),
                'other_amount' => self::fmt($amt('other')),
                'net_amount' => self::fmt($net),
                'sales_count' => count($orderIds),
                'created_by_user_id' => $actorId,
            ]);

            // Claim the merchant rows for this payout (double-pay guard).
            DB::table('pos_sale_commissions')
                ->whereIn('id', $rowIds)
                ->update(['payout_id' => $payout->id]);

            return $payout->fresh();
        });
    }

    private static function fmt(float $omr): string
    {
        return number_format($omr, 3, '.', '');
    }
}
