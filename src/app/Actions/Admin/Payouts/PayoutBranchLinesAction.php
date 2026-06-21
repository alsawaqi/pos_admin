<?php

declare(strict_types=1);

namespace App\Actions\Admin\Payouts;

use App\Models\Payout;
use Illuminate\Support\Facades\DB;

/**
 * The per-branch breakdown of a payout — the "detailed description for each
 * branch" on the settlement statement the merchant receives.
 *
 * Derived (not stored) from the payout's CLAIMED sales: the claim stamps
 * payout_id on the merchant rows, so those order ids are the payout's sales.
 * We then sum every party row of those orders per branch, settled-aware
 * (the bank's ACTUAL fee where reconciled, the estimate otherwise). The claimed
 * orders are frozen — settlement skips any order already claimed into a payout —
 * so this derivation is stable for the life of the payout.
 */
final class PayoutBranchLinesAction
{
    /**
     * @return list<array<string, mixed>>
     */
    public function handle(Payout $payout): array
    {
        $orderIds = DB::table('pos_sale_commissions')
            ->where('payout_id', $payout->id)
            ->where('party_type', 'merchant')
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
                COALESCE(SUM(COALESCE(sc.settled_amount, sc.commission_amount)), 0) AS total
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
            $agg[$bid] ??= ['platform' => 0.0, 'bank' => 0.0, 'other' => 0.0, 'merchant' => 0.0];
            $names[$bid] ??= (string) $r->branch_name;
            $party = (string) $r->party_type;
            if (array_key_exists($party, $agg[$bid])) {
                $agg[$bid][$party] = (float) $r->total;
            }
        }

        $lines = [];
        foreach ($agg as $bid => $a) {
            $gross = $a['platform'] + $a['bank'] + $a['other'] + $a['merchant'];
            $lines[] = [
                'branch_id' => $bid,
                'branch_name' => $names[$bid] ?? '',
                'gross' => self::fmt($gross),
                'platform' => self::fmt($a['platform']),
                'bank' => self::fmt($a['bank']),
                'other' => self::fmt($a['other']),
                'merchant_net' => self::fmt($a['merchant']),
                'num_sales' => (int) ($salesByBranch[$bid] ?? 0),
            ];
        }
        usort($lines, static fn (array $x, array $y): int => (float) $y['merchant_net'] <=> (float) $x['merchant_net']);

        return $lines;
    }

    private static function fmt(float $omr): string
    {
        return number_format($omr, 3, '.', '');
    }
}
