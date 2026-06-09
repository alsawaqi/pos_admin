<?php

declare(strict_types=1);

namespace App\Actions\Admin\Reports;

use App\Enums\OrderStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Platform sales aggregation for the admin app (v2 #16 + #19).
 *
 * One action serves both:
 *   - $companyId === null  → PLATFORM-WIDE view: headline + daily trend
 *     + top merchants + order-type / payment-method mix (admin dashboard).
 *   - $companyId !== null  → SINGLE-MERCHANT view: same shape but scoped
 *     to one company and broken down by_branch (the per-merchant Sales tab).
 *
 * Reads the shared pos_orders / pos_payments tables directly (the admin
 * is NOT tenant-scoped). Money columns are decimal-3 STRINGS so the JSON
 * layer preserves OMR baisas precision. Date expressions are driver-aware
 * (sqlite in tests, Postgres in prod).
 */
final class AdminSalesReportAction
{
    private const TOP_MERCHANTS = 10;

    /** Cap the zero-filled trend; beyond this we return only days with data. */
    private const MAX_TREND_FILL_DAYS = 92;

    /**
     * @return array<string, mixed>
     */
    public function handle(?int $companyId, CarbonInterface $from, CarbonInterface $to): array
    {
        $paid = DB::table('pos_orders')
            ->where('status', OrderStatus::Paid->value)
            ->whereBetween('opened_at', [$from, $to]);
        if ($companyId !== null) {
            $paid->where('company_id', $companyId);
        }

        $headline = (clone $paid)
            ->selectRaw('
                COALESCE(SUM(grand_total), 0) AS gross,
                COALESCE(SUM(subtotal), 0) AS subtotal,
                COALESCE(SUM(discount_total), 0) AS discount,
                COALESCE(SUM(tax_total), 0) AS tax,
                COUNT(*) AS cnt
            ')
            ->first();

        $refunded = DB::table('pos_orders')
            ->where('status', OrderStatus::Refunded->value)
            ->whereBetween('opened_at', [$from, $to]);
        if ($companyId !== null) {
            $refunded->where('company_id', $companyId);
        }
        $refundRow = $refunded
            ->selectRaw('COALESCE(SUM(grand_total), 0) AS refunds, COUNT(*) AS cnt')
            ->first();

        $gross = (float) ($headline?->gross ?? 0);
        $orderCount = (int) ($headline?->cnt ?? 0);

        return [
            'window' => [
                'from' => $from->format('Y-m-d\TH:i:s'),
                'to' => $to->format('Y-m-d\TH:i:s'),
                'company_id' => $companyId,
            ],
            'headline' => [
                'gross_sales' => self::fmt($gross),
                'net_sales' => self::fmt((float) ($headline?->subtotal ?? 0) - (float) ($headline?->discount ?? 0)),
                'discount_total' => self::fmt((float) ($headline?->discount ?? 0)),
                'tax_total' => self::fmt((float) ($headline?->tax ?? 0)),
                'refunds_total' => self::fmt((float) ($refundRow?->refunds ?? 0)),
                'order_count' => $orderCount,
                'refund_count' => (int) ($refundRow?->cnt ?? 0),
                'avg_ticket' => self::fmt($orderCount > 0 ? $gross / $orderCount : 0.0),
            ],
            'sales_trend' => $this->salesTrend($paid, $from, $to),
            'top_merchants' => $companyId === null ? $this->topMerchants($paid) : [],
            'by_branch' => $companyId !== null ? $this->byBranch($paid) : [],
            'by_order_type' => $this->byOrderType($paid),
            'by_payment_method' => $this->byPaymentMethod($paid),
        ];
    }

    /**
     * Daily paid gross + count. Zero-filled across the window when the
     * span is small enough; otherwise only days with data (sorted asc).
     *
     * @return list<array{date: string, gross: string, count: int}>
     */
    private function salesTrend($paid, CarbonInterface $from, CarbonInterface $to): array
    {
        $driver = DB::connection()->getDriverName();
        $dayExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m-%d', opened_at)"
            : "to_char(opened_at, 'YYYY-MM-DD')";

        $rows = (clone $paid)
            ->selectRaw("$dayExpr AS day, COALESCE(SUM(grand_total), 0) AS gross, COUNT(*) AS cnt")
            ->groupByRaw($dayExpr)
            ->get()
            ->keyBy('day');

        $fromD = $from->copy()->startOfDay();
        $span = (int) $fromD->diffInDays($to->copy()->startOfDay()) + 1;

        if ($span >= 1 && $span <= self::MAX_TREND_FILL_DAYS) {
            $series = [];
            for ($i = 0; $i < $span; $i++) {
                $d = $fromD->copy()->addDays($i)->format('Y-m-d');
                $r = $rows->get($d);
                $series[] = [
                    'date' => $d,
                    'gross' => self::fmt((float) ($r->gross ?? 0)),
                    'count' => (int) ($r->cnt ?? 0),
                ];
            }

            return $series;
        }

        return $rows->values()
            ->sortBy('day')
            ->map(static fn ($r): array => [
                'date' => (string) $r->day,
                'gross' => self::fmt((float) $r->gross),
                'count' => (int) $r->cnt,
            ])->values()->all();
    }

    /**
     * Top merchants by gross (platform view only).
     *
     * @return list<array{company_uuid: string, company_name: string, gross: string, count: int}>
     */
    private function topMerchants($paid): array
    {
        return DB::table('pos_companies')
            ->joinSub((clone $paid)->select('company_id', 'grand_total'), 'o', 'o.company_id', '=', 'pos_companies.id')
            ->selectRaw('
                pos_companies.uuid AS company_uuid,
                pos_companies.name AS company_name,
                COALESCE(SUM(o.grand_total), 0) AS gross,
                COUNT(*) AS cnt
            ')
            ->groupBy('pos_companies.id', 'pos_companies.uuid', 'pos_companies.name')
            ->orderByDesc('gross')
            ->limit(self::TOP_MERCHANTS)
            ->get()
            ->map(static fn ($r): array => [
                'company_uuid' => (string) $r->company_uuid,
                'company_name' => (string) $r->company_name,
                'gross' => self::fmt((float) $r->gross),
                'count' => (int) $r->cnt,
            ])->all();
    }

    /**
     * Per-branch gross (single-merchant view only).
     *
     * @return list<array{branch_id: int, branch_name: string, gross: string, count: int}>
     */
    private function byBranch($paid): array
    {
        return DB::table('pos_branches')
            ->joinSub((clone $paid)->select('branch_id', 'grand_total'), 'o', 'o.branch_id', '=', 'pos_branches.id')
            ->selectRaw('
                pos_branches.id AS branch_id,
                pos_branches.name AS branch_name,
                COALESCE(SUM(o.grand_total), 0) AS gross,
                COUNT(*) AS cnt
            ')
            ->groupBy('pos_branches.id', 'pos_branches.name')
            ->orderByDesc('gross')
            ->get()
            ->map(static fn ($r): array => [
                'branch_id' => (int) $r->branch_id,
                'branch_name' => (string) $r->branch_name,
                'gross' => self::fmt((float) $r->gross),
                'count' => (int) $r->cnt,
            ])->all();
    }

    /**
     * @return list<array{type: string, gross: string, count: int}>
     */
    private function byOrderType($paid): array
    {
        return (clone $paid)
            ->selectRaw('order_type AS type, COALESCE(SUM(grand_total), 0) AS gross, COUNT(*) AS cnt')
            ->groupBy('order_type')
            ->orderBy('order_type')
            ->get()
            ->map(static fn ($r): array => [
                'type' => (string) $r->type,
                'gross' => self::fmt((float) $r->gross),
                'count' => (int) $r->cnt,
            ])->all();
    }

    /**
     * @return list<array{method: string, amount: string, count: int}>
     */
    private function byPaymentMethod($paid): array
    {
        return DB::table('pos_payments')
            ->joinSub((clone $paid)->select('id'), 'o', 'o.id', '=', 'pos_payments.order_id')
            ->where('pos_payments.status', 'success')
            ->selectRaw('pos_payments.method AS method, COALESCE(SUM(pos_payments.amount), 0) AS amount, COUNT(*) AS cnt')
            ->groupBy('pos_payments.method')
            ->orderBy('pos_payments.method')
            ->get()
            ->map(static fn ($r): array => [
                'method' => (string) $r->method,
                'amount' => self::fmt((float) $r->amount),
                'count' => (int) $r->cnt,
            ])->all();
    }

    private static function fmt(float $omr): string
    {
        return number_format($omr, 3, '.', '');
    }
}
