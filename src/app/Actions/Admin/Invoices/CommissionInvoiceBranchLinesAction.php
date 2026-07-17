<?php

declare(strict_types=1);

namespace App\Actions\Admin\Invoices;

use App\Models\CommissionInvoice;
use Illuminate\Support\Facades\DB;

/**
 * The per-branch breakdown of a commission invoice — the statement detail the
 * merchant receives ("commission owed per branch"). Mirror of
 * {@see \App\Actions\Admin\Payouts\PayoutBranchLinesAction}, summing the platform
 * + other cut owed (and the merchant's kept residual, for context) instead of the
 * merchant payout.
 *
 * Derived (not stored) from the invoice's CLAIMED rows: issuing stamps invoice_id
 * on the platform/other rows, so those order ids are the invoice's sales. The
 * claimed rows are frozen (an invoice is never re-issued over the same rows), so
 * this derivation is stable for the life of the invoice.
 */
final class CommissionInvoiceBranchLinesAction
{
    /**
     * @return list<array<string, mixed>>
     */
    public function handle(CommissionInvoice $invoice): array
    {
        $orderIds = DB::table('pos_sale_commissions')
            ->where('invoice_id', $invoice->id)
            ->pluck('order_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($orderIds === []) {
            return [];
        }

        $partyRows = DB::table('pos_sale_commissions as sc')
            ->join('pos_branches', 'pos_branches.id', '=', 'sc.branch_id')
            ->whereIn('sc.order_id', $orderIds)
            ->selectRaw('
                sc.branch_id AS branch_id,
                pos_branches.name AS branch_name,
                sc.party_type AS party_type,
                COALESCE(SUM(sc.commission_amount), 0) AS total
            ')
            ->groupBy('sc.branch_id', 'pos_branches.name', 'sc.party_type')
            ->get();

        $salesByBranch = DB::table('pos_sale_commissions')
            ->whereIn('order_id', $orderIds)
            ->selectRaw('branch_id, COUNT(DISTINCT order_id) AS sales')
            ->groupBy('branch_id')
            ->pluck('sales', 'branch_id');

        /** @var array<int, array<string, float>> $agg */
        $agg = [];
        /** @var array<int, string> $names */
        $names = [];
        foreach ($partyRows as $r) {
            $bid = (int) $r->branch_id;
            $agg[$bid] ??= ['platform' => 0.0, 'other' => 0.0, 'merchant' => 0.0];
            $names[$bid] ??= (string) $r->branch_name;
            $party = (string) $r->party_type;
            if (array_key_exists($party, $agg[$bid])) {
                $agg[$bid][$party] = (float) $r->total;
            }
        }

        $lines = [];
        foreach ($agg as $bid => $a) {
            $owed = $a['platform'] + $a['other'];
            $gross = $a['platform'] + $a['other'] + $a['merchant'];
            $lines[] = [
                'branch_id' => $bid,
                'branch_name' => $names[$bid] ?? '',
                'gross' => self::fmt($gross),
                'platform' => self::fmt($a['platform']),
                'other' => self::fmt($a['other']),
                'merchant_kept' => self::fmt($a['merchant']),
                'total_owed' => self::fmt($owed),
                'num_sales' => (int) ($salesByBranch[$bid] ?? 0),
            ];
        }
        usort($lines, static fn (array $x, array $y): int => (float) $y['total_owed'] <=> (float) $x['total_owed']);

        return $lines;
    }

    private static function fmt(float $omr): string
    {
        return number_format($omr, 3, '.', '');
    }
}
