<?php

declare(strict_types=1);

namespace App\Actions\Admin\Orders;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The Sales-tab drill — merchants → branches, with per-tender-method totals.
 *
 * The admin's daily entry point: who sold, how much, and how it was paid. One
 * row per merchant (sales count + gross + cash/card/bank-POS split) expanding to
 * its branches, from which the admin opens the per-terminal verification
 * workspace. PAID orders only (a sale = money in); gross is grand_total (tax
 * included; the round-up is a separate charity donation and is never part of
 * grand_total). Method totals come from the orders' non-failed tenders — cash,
 * card, bank_pos; anything else (loyalty/gift/split bookkeeping) lands in other.
 */
final class SalesSummaryAction
{
    /**
     * @param  string|null  $scope  null = every paid sale; 'cash_bank' = ONLY
     *                              pure cash/bank-POS sales (a cash/bank_pos
     *                              tender AND no card tender — the separate
     *                              merchant-holds-the-money page's drill, the
     *                              same predicate the commission invoice bills).
     * @return list<array<string, mixed>>
     */
    public function handle(CarbonInterface $from, CarbonInterface $to, ?string $scope = null): array
    {
        // Restrict any orders-rooted query to pure cash/bank-POS sales.
        $cashBankOnly = static function ($q, string $orderIdColumn): void {
            $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('pos_payments as heldpay')
                ->whereColumn('heldpay.order_id', $orderIdColumn)
                ->whereIn('heldpay.method', ['cash', 'bank_pos'])
                ->where('heldpay.status', '<>', 'failed'))
                ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('pos_payments as cardpay')
                    ->whereColumn('cardpay.order_id', $orderIdColumn)
                    ->where('cardpay.method', 'card')
                    ->where('cardpay.status', '<>', 'failed'));
        };

        $orderAgg = DB::table('pos_orders')
            ->where('status', 'paid')
            ->whereBetween('opened_at', [$from, $to])
            ->when($scope === 'cash_bank', fn ($q) => $cashBankOnly($q, 'pos_orders.id'))
            ->selectRaw('company_id, branch_id, COUNT(*) AS sales, COALESCE(SUM(grand_total), 0) AS gross')
            ->groupBy('company_id', 'branch_id')
            ->get();

        if ($orderAgg->isEmpty()) {
            return [];
        }

        $methodAgg = DB::table('pos_payments')
            ->join('pos_orders', 'pos_orders.id', '=', 'pos_payments.order_id')
            ->where('pos_orders.status', 'paid')
            ->whereBetween('pos_orders.opened_at', [$from, $to])
            ->where('pos_payments.status', '<>', 'failed')
            ->when($scope === 'cash_bank', fn ($q) => $cashBankOnly($q, 'pos_orders.id'))
            ->selectRaw('pos_orders.company_id, pos_orders.branch_id, pos_payments.method, COALESCE(SUM(pos_payments.amount), 0) AS total')
            ->groupBy('pos_orders.company_id', 'pos_orders.branch_id', 'pos_payments.method')
            ->get();

        // Step 3 — the verification progress per branch: how many of the window's
        // commissioned sales the admin has verified one-by-one. A branch reads
        // COMPLETED when verified == commissioned (orders without commission rows
        // have nothing to verify and never block completion). Rows of an order
        // settle together, so DISTINCT-on-settled counts whole orders.
        $verifyAgg = DB::table('pos_sale_commissions as sc')
            ->join('pos_orders', 'pos_orders.id', '=', 'sc.order_id')
            ->where('pos_orders.status', 'paid')
            ->whereBetween('pos_orders.opened_at', [$from, $to])
            ->when($scope === 'cash_bank', fn ($q) => $cashBankOnly($q, 'pos_orders.id'))
            ->selectRaw('pos_orders.company_id, pos_orders.branch_id,
                COUNT(DISTINCT sc.order_id) AS commissioned,
                COUNT(DISTINCT CASE WHEN sc.is_settled THEN sc.order_id END) AS verified')
            ->groupBy('pos_orders.company_id', 'pos_orders.branch_id')
            ->get();

        /** @var array<int, array<int, array{commissioned: int, verified: int}>> $verify */
        $verify = [];
        foreach ($verifyAgg as $r) {
            $verify[(int) $r->company_id][(int) $r->branch_id] = [
                'commissioned' => (int) $r->commissioned,
                'verified' => (int) $r->verified,
            ];
        }

        /** @var array<int, array<int, array<string, float>>> $methods company => branch => bucket => OMR */
        $methods = [];
        foreach ($methodAgg as $r) {
            $bucket = match ((string) $r->method) {
                'cash' => 'cash',
                'card' => 'card',
                'bank_pos' => 'bank_pos',
                default => 'other',
            };
            $methods[(int) $r->company_id][(int) $r->branch_id][$bucket] =
                ($methods[(int) $r->company_id][(int) $r->branch_id][$bucket] ?? 0.0) + (float) $r->total;
        }

        $companyIds = $orderAgg->pluck('company_id')->unique()->all();
        $branchIds = $orderAgg->pluck('branch_id')->unique()->all();
        $companies = DB::table('pos_companies')->whereIn('id', $companyIds)->get(['id', 'uuid', 'name'])->keyBy('id');
        $branches = DB::table('pos_branches')->whereIn('id', $branchIds)->get(['id', 'uuid', 'name'])->keyBy('id');

        $money = static fn (float $omr): string => number_format($omr, 3, '.', '');
        $bucketOf = static function (int $cid, int $bid) use ($methods): array {
            $m = $methods[$cid][$bid] ?? [];

            return [
                'cash_total' => $m['cash'] ?? 0.0,
                'card_total' => $m['card'] ?? 0.0,
                'bank_pos_total' => $m['bank_pos'] ?? 0.0,
                'other_total' => $m['other'] ?? 0.0,
            ];
        };

        /** @var array<int, array<string, mixed>> $agg */
        $agg = [];
        foreach ($orderAgg as $r) {
            $cid = (int) $r->company_id;
            $bid = (int) $r->branch_id;
            $company = $companies->get($cid);
            $branch = $branches->get($bid);
            if ($company === null || $branch === null) {
                continue;
            }
            $agg[$cid] ??= [
                'company_uuid' => (string) $company->uuid,
                'company_name' => (string) $company->name,
                'sales_count' => 0,
                'gross' => 0.0,
                'cash_total' => 0.0,
                'card_total' => 0.0,
                'bank_pos_total' => 0.0,
                'other_total' => 0.0,
                'commissioned_count' => 0,
                'verified_count' => 0,
                'branches' => [],
            ];
            $buckets = $bucketOf($cid, $bid);
            $v = $verify[$cid][$bid] ?? ['commissioned' => 0, 'verified' => 0];
            $agg[$cid]['sales_count'] += (int) $r->sales;
            $agg[$cid]['gross'] += (float) $r->gross;
            $agg[$cid]['commissioned_count'] += $v['commissioned'];
            $agg[$cid]['verified_count'] += $v['verified'];
            foreach ($buckets as $k => $b) {
                $agg[$cid][$k] += $b;
            }
            $agg[$cid]['branches'][] = [
                'branch_uuid' => (string) $branch->uuid,
                'branch_name' => (string) $branch->name,
                'sales_count' => (int) $r->sales,
                'gross_total' => $money((float) $r->gross),
                'cash_total' => $money($buckets['cash_total']),
                'card_total' => $money($buckets['card_total']),
                'bank_pos_total' => $money($buckets['bank_pos_total']),
                'other_total' => $money($buckets['other_total']),
                'commissioned_count' => $v['commissioned'],
                'verified_count' => $v['verified'],
            ];
        }

        $out = [];
        foreach ($agg as $m) {
            usort($m['branches'], static fn (array $x, array $y): int => (float) $y['gross_total'] <=> (float) $x['gross_total']);
            $out[] = [
                'company_uuid' => $m['company_uuid'],
                'company_name' => $m['company_name'],
                'sales_count' => $m['sales_count'],
                'gross_total' => $money($m['gross']),
                'cash_total' => $money($m['cash_total']),
                'card_total' => $money($m['card_total']),
                'bank_pos_total' => $money($m['bank_pos_total']),
                'other_total' => $money($m['other_total']),
                'commissioned_count' => $m['commissioned_count'],
                'verified_count' => $m['verified_count'],
                'branches' => $m['branches'],
            ];
        }
        usort($out, static fn (array $x, array $y): int => (float) $y['gross_total'] <=> (float) $x['gross_total']);

        return $out;
    }
}
