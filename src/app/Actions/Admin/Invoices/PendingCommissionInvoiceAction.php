<?php

declare(strict_types=1);

namespace App\Actions\Admin\Invoices;

use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The "commission to bill" to-do — merchants (and their branches) that have
 * un-invoiced cash/bank_pos commission owed in the window. Mirror of
 * {@see \App\Actions\Admin\Reconciliation\SettlementPendingAction}, but for the
 * reverse direction: pending_owed is Σ(platform + other) the merchant owes the
 * platform on pure cash/bank_pos sales not yet claimed into an invoice.
 *
 * Drives the merchant → branch drill; issuing the invoice is
 * {@see CreateCommissionInvoiceAction} over the same predicate.
 */
final class PendingCommissionInvoiceAction
{
    private const MERCHANT_HELD_METHODS = ['cash', 'bank_pos'];

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = DB::table('pos_sale_commissions')
            ->whereIn('party_type', ['platform', 'other'])
            ->whereNull('invoice_id')
            ->where('commission_amount', '>', 0)
            ->whereBetween('occurred_at', [$from, $to])
            ->whereExists(fn ($s) => $s->select(DB::raw(1))->from('pos_payments as heldpay')
                ->whereColumn('heldpay.order_id', 'pos_sale_commissions.order_id')
                ->whereIn('heldpay.method', self::MERCHANT_HELD_METHODS)
                ->where('heldpay.status', '<>', 'failed'))
            ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('pos_payments as cardpay')
                ->whereColumn('cardpay.order_id', 'pos_sale_commissions.order_id')
                ->where('cardpay.method', 'card')
                ->where('cardpay.status', '<>', 'failed'))
            ->get(['order_id', 'company_id', 'branch_id', 'commission_amount']);

        if ($rows->isEmpty()) {
            return [];
        }

        /** @var array<int, array<int, array{orders: array<int, bool>, owed: int}>> $agg */
        $agg = [];
        foreach ($rows as $r) {
            $cid = (int) $r->company_id;
            $bid = (int) $r->branch_id;
            $agg[$cid][$bid] ??= ['orders' => [], 'owed' => 0];
            $agg[$cid][$bid]['orders'][(int) $r->order_id] = true;
            $agg[$cid][$bid]['owed'] += Money::toBaisas($r->commission_amount);
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
            $merchantOwed = 0;
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
                    'pending_owed' => Money::toOmr($a['owed']),
                ];
                $merchantOwed += $a['owed'];
                $merchantOrders += $count;
            }
            usort($branchList, static fn (array $x, array $y): int => (float) $y['pending_owed'] <=> (float) $x['pending_owed']);

            $out[] = [
                'company_uuid' => (string) $company->uuid,
                'company_name' => (string) $company->name,
                'pending_orders' => $merchantOrders,
                'pending_owed' => Money::toOmr($merchantOwed),
                'branches' => $branchList,
            ];
        }
        usort($out, static fn (array $x, array $y): int => (float) $y['pending_owed'] <=> (float) $x['pending_owed']);

        return $out;
    }
}
