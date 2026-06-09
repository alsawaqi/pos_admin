<?php

declare(strict_types=1);

namespace App\Actions\Admin\Reports;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * v2 #17 (Phase A) — platform SETTLEMENT report.
 *
 * Aggregates the per-sale commission ledger (pos_sale_commissions) across all
 * merchants for a date range. Per merchant it shows, in OMR: gross sales, the
 * platform take, the bank (acquirer) take, any other-party take, and the
 * MERCHANT NET (their residual — what the platform settles to them). The
 * platform headline sums these so the operator sees total platform revenue +
 * total merchant payable for the window.
 *
 * Direction-agnostic visibility over the recorded splits; the stateful payout
 * (pos_payouts) is Phase B. The admin is NOT tenant-scoped; an optional
 * $companyId narrows to one merchant. Money is decimal-3 strings; filtered on
 * occurred_at (the sale close time).
 */
final class AdminSettlementReportAction
{
    /**
     * @return array<string, mixed>
     */
    public function handle(?int $companyId, CarbonInterface $from, CarbonInterface $to): array
    {
        // Per-merchant, per-party sums.
        $partyRows = DB::table('pos_sale_commissions as sc')
            ->join('pos_companies', 'pos_companies.id', '=', 'sc.company_id')
            ->whereBetween('sc.occurred_at', [$from, $to])
            ->when($companyId !== null, fn ($q) => $q->where('sc.company_id', $companyId))
            ->selectRaw('
                sc.company_id AS company_id,
                pos_companies.uuid AS company_uuid,
                pos_companies.name AS company_name,
                sc.party_type AS party_type,
                COALESCE(SUM(sc.commission_amount), 0) AS total
            ')
            ->groupBy('sc.company_id', 'pos_companies.uuid', 'pos_companies.name', 'sc.party_type')
            ->get();

        // Sales count per merchant (distinct orders).
        $salesByCompany = DB::table('pos_sale_commissions')
            ->whereBetween('occurred_at', [$from, $to])
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->selectRaw('company_id, COUNT(DISTINCT order_id) AS sales')
            ->groupBy('company_id')
            ->pluck('sales', 'company_id');

        /** @var array<int, array<string, mixed>> $merchants */
        $merchants = [];
        foreach ($partyRows as $r) {
            $cid = (int) $r->company_id;
            $merchants[$cid] ??= [
                'company_uuid' => (string) $r->company_uuid,
                'company_name' => (string) $r->company_name,
                'platform' => 0.0, 'bank' => 0.0, 'other' => 0.0, 'merchant' => 0.0,
            ];
            $party = (string) $r->party_type;
            if (array_key_exists($party, $merchants[$cid])) {
                $merchants[$cid][$party] = (float) $r->total;
            }
        }

        $byMerchant = [];
        $totPlatform = $totBank = $totOther = $totMerchant = $totSales = 0.0;
        foreach ($merchants as $cid => $m) {
            $gross = $m['platform'] + $m['bank'] + $m['other'] + $m['merchant'];
            $sales = (int) ($salesByCompany[$cid] ?? 0);
            $totPlatform += $m['platform'];
            $totBank += $m['bank'];
            $totOther += $m['other'];
            $totMerchant += $m['merchant'];
            $totSales += $sales;
            $byMerchant[] = [
                'company_id' => $cid,
                'company_uuid' => $m['company_uuid'],
                'company_name' => $m['company_name'],
                'gross' => self::fmt($gross),
                'platform' => self::fmt($m['platform']),
                'bank' => self::fmt($m['bank']),
                'other' => self::fmt($m['other']),
                'merchant_net' => self::fmt($m['merchant']),
                'num_sales' => $sales,
            ];
        }
        usort($byMerchant, static fn (array $x, array $y): int => (float) $y['merchant_net'] <=> (float) $x['merchant_net']);

        $totGross = $totPlatform + $totBank + $totOther + $totMerchant;

        return [
            'window' => [
                'from' => $from->format('Y-m-d\TH:i:s'),
                'to' => $to->format('Y-m-d\TH:i:s'),
                'company_id' => $companyId,
            ],
            'headline' => [
                'gross' => self::fmt($totGross),
                'platform_revenue' => self::fmt($totPlatform),
                'bank_total' => self::fmt($totBank),
                'other_total' => self::fmt($totOther),
                'merchant_payable' => self::fmt($totMerchant),
                'num_sales' => (int) $totSales,
                'num_merchants' => count($byMerchant),
            ],
            'by_merchant' => $byMerchant,
        ];
    }

    private static function fmt(float $omr): string
    {
        return number_format($omr, 3, '.', '');
    }
}
