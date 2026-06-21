<?php

declare(strict_types=1);

namespace App\Actions\Admin\Reconciliation;

use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The daily settlement to-do — merchants (and their branches) that have card
 * sales still needing reconciliation in the window.
 *
 * "Needs settlement" = an unsettled card order (estimated bank row > 0, not yet
 * settled, not yet claimed into a payout). pending_net is the merchant's
 * estimated take for those orders (what they'd be paid before any bank-fee
 * adjustment). Drives the merchant → branch drill-down; the per-order detail
 * comes from SettlementOrdersAction.
 */
final class SettlementPendingAction
{
    /**
     * @return list<array<string, mixed>>
     */
    public function handle(CarbonInterface $from, CarbonInterface $to): array
    {
        $bankRows = DB::table('pos_sale_commissions')
            ->where('party_type', 'bank')
            ->where('is_settled', false)
            ->where('commission_amount', '>', 0)
            ->whereBetween('occurred_at', [$from, $to])
            ->whereNotExists(function ($sub): void {
                $sub->select(DB::raw(1))
                    ->from('pos_sale_commissions as claimed')
                    ->whereColumn('claimed.order_id', 'pos_sale_commissions.order_id')
                    ->whereNotNull('claimed.payout_id');
            })
            ->pluck('order_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($bankRows === []) {
            return [];
        }

        // Merchant rows of those orders give the per-branch net + order count.
        $merchantRows = DB::table('pos_sale_commissions')
            ->whereIn('order_id', $bankRows)
            ->where('party_type', 'merchant')
            ->get(['order_id', 'company_id', 'branch_id', 'commission_amount']);

        /** @var array<int, array<int, array{orders: array<int, bool>, net: int}>> $agg */
        $agg = [];
        foreach ($merchantRows as $r) {
            $cid = (int) $r->company_id;
            $bid = (int) $r->branch_id;
            $agg[$cid][$bid] ??= ['orders' => [], 'net' => 0];
            $agg[$cid][$bid]['orders'][(int) $r->order_id] = true;
            $agg[$cid][$bid]['net'] += Money::toBaisas($r->commission_amount);
        }

        $companies = DB::table('pos_companies')->whereIn('id', array_keys($agg))->get(['id', 'uuid', 'name'])->keyBy('id');
        $branchIds = [];
        foreach ($agg as $branches) {
            foreach (array_keys($branches) as $bid) {
                $branchIds[$bid] = true;
            }
        }
        $branches = DB::table('pos_branches')->whereIn('id', array_keys($branchIds))->get(['id', 'uuid', 'name'])->keyBy('id');

        $out = [];
        foreach ($agg as $cid => $branchAgg) {
            $company = $companies->get($cid);
            if ($company === null) {
                continue;
            }
            $branchList = [];
            $merchantNet = 0;
            $merchantOrders = 0;
            foreach ($branchAgg as $bid => $a) {
                $branch = $branches->get($bid);
                if ($branch === null) {
                    continue;
                }
                $count = count($a['orders']);
                $branchList[] = [
                    'branch_uuid' => (string) $branch->uuid,
                    'branch_name' => (string) $branch->name,
                    'pending_orders' => $count,
                    'pending_net' => Money::toOmr($a['net']),
                ];
                $merchantNet += $a['net'];
                $merchantOrders += $count;
            }
            usort($branchList, static fn (array $x, array $y): int => (float) $y['pending_net'] <=> (float) $x['pending_net']);

            $out[] = [
                'company_uuid' => (string) $company->uuid,
                'company_name' => (string) $company->name,
                'pending_orders' => $merchantOrders,
                'pending_net' => Money::toOmr($merchantNet),
                'branches' => $branchList,
            ];
        }
        usort($out, static fn (array $x, array $y): int => (float) $y['pending_net'] <=> (float) $x['pending_net']);

        return $out;
    }
}
