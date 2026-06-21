<?php

declare(strict_types=1);

namespace App\Actions\Admin\Reconciliation;

use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The per-ORDER reconciliation worklist for a branch + day — every card sale
 * the admin checks one-by-one against the bank statement before settling.
 *
 * Each row carries the bank-matching evidence (terminal id + Soft POS auth code
 * + reference, per card tender), the card SALE amount (the commission base — the
 * round-up is a separate charity donation, NOT a sale, shown apart), the
 * estimated split, and (if already settled) the settled figures. Default lists
 * the UNSETTLED card orders not yet claimed into a payout (the to-do); status
 * 'settled' / 'all' include reconciled ones for review.
 */
final class SettlementOrdersAction
{
    /**
     * @return list<array<string, mixed>>
     */
    public function handle(int $companyId, int $branchId, CarbonInterface $from, CarbonInterface $to, string $status = 'unsettled'): array
    {
        $bankRows = DB::table('pos_sale_commissions')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('party_type', 'bank')
            ->where('commission_amount', '>', 0)
            ->whereBetween('occurred_at', [$from, $to])
            ->when($status === 'unsettled', fn ($q) => $q->where('is_settled', false))
            ->when($status === 'settled', fn ($q) => $q->where('is_settled', true))
            ->pluck('order_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($bankRows === []) {
            return [];
        }
        $orderIds = $bankRows;

        $rowsByOrder = DB::table('pos_sale_commissions')
            ->whereIn('order_id', $orderIds)
            ->get(['order_id', 'party_type', 'commission_amount', 'settled_amount', 'is_settled', 'payout_id'])
            ->groupBy('order_id');

        $orders = DB::table('pos_orders')
            ->whereIn('id', $orderIds)
            ->get(['id', 'uuid', 'receipt_number', 'opened_at', 'closed_at', 'grand_total'])
            ->keyBy('id');

        $tendersByOrder = DB::table('pos_payments')
            ->whereIn('order_id', $orderIds)
            ->where('method', 'card')
            ->where('status', '!=', 'failed')
            ->orderBy('id')
            ->get(['order_id', 'amount', 'terminal_id', 'softpos_auth_code', 'softpos_reference', 'captured_at'])
            ->groupBy('order_id');

        $roundupByOrder = DB::table('pos_roundup_donations')
            ->whereIn('order_id', $orderIds)
            ->selectRaw('order_id, COALESCE(SUM(amount), 0) AS total')
            ->groupBy('order_id')
            ->pluck('total', 'order_id');

        $out = [];
        foreach ($orderIds as $orderId) {
            $order = $orders->get($orderId);
            if ($order === null) {
                continue;
            }

            $estBank = 0;
            $estPlatform = 0;
            $estMerchant = 0;
            $settledBank = 0;
            $settledMerchant = 0;
            $isSettled = false;
            $isPaidOut = false;
            foreach ($rowsByOrder->get($orderId, collect()) as $row) {
                $est = Money::toBaisas($row->commission_amount);
                $settled = $row->settled_amount !== null ? Money::toBaisas($row->settled_amount) : null;
                if ($row->party_type === 'bank') {
                    $estBank += $est;
                    $settledBank += $settled ?? 0;
                } elseif ($row->party_type === 'platform') {
                    $estPlatform += $est;
                } elseif ($row->party_type === 'merchant') {
                    $estMerchant += $est;
                    $settledMerchant += $settled ?? 0;
                }
                if ((bool) $row->is_settled) {
                    $isSettled = true;
                }
                if ($row->payout_id !== null) {
                    $isPaidOut = true;
                }
            }

            $cardBaisas = 0;
            $tenders = [];
            foreach ($tendersByOrder->get($orderId, collect()) as $t) {
                $cardBaisas += Money::toBaisas($t->amount);
                $tenders[] = [
                    'amount' => number_format((float) $t->amount, 3, '.', ''),
                    'terminal_id' => $t->terminal_id,
                    'auth_code' => $t->softpos_auth_code,
                    'reference' => $t->softpos_reference,
                    'captured_at' => $t->captured_at,
                ];
            }

            $out[] = [
                'order_uuid' => (string) $order->uuid,
                'receipt_number' => $order->receipt_number,
                'occurred_at' => $order->closed_at ?? $order->opened_at,
                'grand_total' => number_format((float) $order->grand_total, 3, '.', ''),
                'card_amount' => Money::toOmr($cardBaisas),
                'roundup' => Money::toOmr(Money::toBaisas($roundupByOrder[$orderId] ?? 0)),
                'estimated_bank' => Money::toOmr($estBank),
                'estimated_platform' => Money::toOmr($estPlatform),
                'estimated_merchant_net' => Money::toOmr($estMerchant),
                'is_settled' => $isSettled,
                'is_paid_out' => $isPaidOut,
                'settled_bank' => $isSettled ? Money::toOmr($settledBank) : null,
                'settled_merchant_net' => $isSettled ? Money::toOmr($settledMerchant) : null,
                'tenders' => $tenders,
            ];
        }

        // Chronological — matches how a bank statement is read.
        usort($out, static fn (array $x, array $y): int => (string) ($x['occurred_at'] ?? '') <=> (string) ($y['occurred_at'] ?? ''));

        return $out;
    }
}
