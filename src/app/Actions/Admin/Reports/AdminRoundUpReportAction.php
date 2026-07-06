<?php

declare(strict_types=1);

namespace App\Actions\Admin\Reports;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * v2 #18 — platform round-up donation report.
 *
 * Aggregates pos_roundup_donations across all merchants for a window: total
 * raised for charity (successful donations) + donation/pending/failed counts,
 * and a per-merchant breakdown. The admin is NOT tenant-scoped; an optional
 * $companyId narrows to one merchant. Money is decimal-3 strings; filtered on
 * occurred_at.
 */
final class AdminRoundUpReportAction
{
    /**
     * @return array<string, mixed>
     */
    public function handle(?int $companyId, CarbonInterface $from, CarbonInterface $to): array
    {
        $base = DB::table('pos_roundup_donations')
            ->whereBetween('occurred_at', [$from, $to])
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId));

        // Platform-wide per-status totals.
        $byStatus = (clone $base)
            ->selectRaw('status, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS cnt')
            ->groupBy('status')
            ->get()
            ->keyBy('status');
        $cnt = static fn (string $s): int => (int) ($byStatus[$s]->cnt ?? 0);
        $raised = (float) ($byStatus['success']->total ?? 0);

        // Per-merchant raised (successful donations).
        $byMerchant = DB::table('pos_roundup_donations as rd')
            ->join('pos_companies', 'pos_companies.id', '=', 'rd.company_id')
            ->whereBetween('rd.occurred_at', [$from, $to])
            ->where('rd.status', 'success')
            ->when($companyId !== null, fn ($q) => $q->where('rd.company_id', $companyId))
            ->selectRaw('
                pos_companies.uuid AS company_uuid,
                pos_companies.name AS company_name,
                COALESCE(SUM(rd.amount), 0) AS total,
                COUNT(*) AS cnt
            ')
            ->groupBy('pos_companies.id', 'pos_companies.uuid', 'pos_companies.name')
            ->orderByDesc('total')
            ->get()
            ->map(static fn ($r): array => [
                'company_uuid' => (string) $r->company_uuid,
                'company_name' => (string) $r->company_name,
                'total_raised' => self::fmt((float) $r->total),
                'donation_count' => (int) $r->cnt,
            ])->all();

        // Per-branch raised, resolving the branch NAME + geo names from the
        // pos/charity shared tables (soft-joined; ids share the charity geo
        // id-space). MAX() keeps Postgres' strict GROUP BY happy.
        $byBranch = DB::table('pos_roundup_donations as rd')
            ->leftJoin('pos_branches as b', 'b.id', '=', 'rd.branch_id')
            ->leftJoin('countries as co', 'co.id', '=', 'rd.country_id')
            ->leftJoin('regions as rg', 'rg.id', '=', 'rd.region_id')
            ->leftJoin('cities as ci', 'ci.id', '=', 'rd.city_id')
            ->whereBetween('rd.occurred_at', [$from, $to])
            ->where('rd.status', 'success')
            ->when($companyId !== null, fn ($q) => $q->where('rd.company_id', $companyId))
            ->selectRaw('
                rd.branch_id,
                MAX(b.name) AS branch_name,
                MAX(co.name) AS country_name,
                MAX(rg.name) AS region_name,
                MAX(ci.name) AS city_name,
                COALESCE(SUM(rd.amount), 0) AS total,
                COUNT(*) AS cnt
            ')
            ->groupBy('rd.branch_id')
            ->orderByDesc('total')
            ->get()
            ->map(static fn ($r): array => [
                'branch_id' => (int) $r->branch_id,
                'branch_name' => (string) ($r->branch_name ?? ''),
                'country' => $r->country_name !== null ? (string) $r->country_name : null,
                'region' => $r->region_name !== null ? (string) $r->region_name : null,
                'city' => $r->city_name !== null ? (string) $r->city_name : null,
                'total_raised' => self::fmt((float) $r->total),
                'donation_count' => (int) $r->cnt,
            ])->all();

        return [
            'window' => [
                'from' => $from->format('Y-m-d\TH:i:s'),
                'to' => $to->format('Y-m-d\TH:i:s'),
                'company_id' => $companyId,
            ],
            'headline' => [
                'total_raised' => self::fmt($raised),
                'donation_count' => $cnt('success'),
                'pending_count' => $cnt('pending'),
                'failed_count' => $cnt('fail'),
                'num_merchants' => count($byMerchant),
            ],
            'by_merchant' => $byMerchant,
            'by_branch' => $byBranch,
        ];
    }

    private static function fmt(float $omr): string
    {
        return number_format($omr, 3, '.', '');
    }
}
