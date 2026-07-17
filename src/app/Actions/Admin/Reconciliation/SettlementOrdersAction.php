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
     * @param  string  $method  'card' (default — the bank-fee to-do), 'cash_bank'
     *                          (ONLY pure cash/bank-POS sales — the separate
     *                          merchant-holds-the-money workspace whose
     *                          verification feeds the commission INVOICE), or
     *                          'all' (both, for review).
     * @return list<array<string, mixed>>
     */
    public function handle(int $companyId, int $branchId, CarbonInterface $from, CarbonInterface $to, string $status = 'unsettled', string $method = 'card'): array
    {
        // Exclude orders already claimed into a payout (finalised) — matches the
        // pending drill-down + settle eligibility, so the worklist shows exactly
        // the orders that can actually be settled. Reused across both queries.
        $notClaimed = static function ($sub): void {
            $sub->select(DB::raw(1))
                ->from('pos_sale_commissions as claimed')
                ->whereColumn('claimed.order_id', 'pos_sale_commissions.order_id')
                ->whereNotNull('claimed.payout_id');
        };

        // CARD orders — a positive BANK cut (card money carries the bank fee).
        $cardOrderIds = DB::table('pos_sale_commissions')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('party_type', 'bank')
            ->where('commission_amount', '>', 0)
            ->whereBetween('occurred_at', [$from, $to])
            ->when($status === 'unsettled', fn ($q) => $q->where('is_settled', false))
            ->when($status === 'settled', fn ($q) => $q->where('is_settled', true))
            ->whereNotExists($notClaimed)
            ->pluck('order_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($method === 'all') {
            // ALSO cash sales — a commissioned order with no bank cut. Keyed off
            // the merchant residual row (every commissioned order has exactly one)
            // so cash sales (no 'bank' row) are included; the union dedupes card.
            $allOrderIds = DB::table('pos_sale_commissions')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('party_type', 'merchant')
                ->whereBetween('occurred_at', [$from, $to])
                ->when($status === 'unsettled', fn ($q) => $q->where('is_settled', false))
                ->when($status === 'settled', fn ($q) => $q->where('is_settled', true))
                ->whereNotExists($notClaimed)
                ->pluck('order_id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
            $orderIds = array_values(array_unique(array_merge($cardOrderIds, $allOrderIds)));
        } elseif ($method === 'cash_bank') {
            // ONLY pure cash/bank-POS sales — a cash/bank_pos tender AND no card
            // tender (the same predicate the commission invoice bills). Keyed off
            // the merchant residual row; card money never appears here (it lives
            // in the card workspace — the two flows are deliberately separated).
            $orderIds = DB::table('pos_sale_commissions')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('party_type', 'merchant')
                ->whereBetween('occurred_at', [$from, $to])
                ->when($status === 'unsettled', fn ($q) => $q->where('is_settled', false))
                ->when($status === 'settled', fn ($q) => $q->where('is_settled', true))
                ->whereNotExists($notClaimed)
                ->whereExists(fn ($s) => $s->select(DB::raw(1))->from('pos_payments as heldpay')
                    ->whereColumn('heldpay.order_id', 'pos_sale_commissions.order_id')
                    ->whereIn('heldpay.method', ['cash', 'bank_pos'])
                    ->where('heldpay.status', '<>', 'failed'))
                ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('pos_payments as cardpay')
                    ->whereColumn('cardpay.order_id', 'pos_sale_commissions.order_id')
                    ->where('cardpay.method', 'card')
                    ->where('cardpay.status', '<>', 'failed'))
                ->pluck('order_id')
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
        } else {
            $orderIds = $cardOrderIds;
        }

        if ($orderIds === []) {
            return [];
        }

        $rowsByOrder = DB::table('pos_sale_commissions')
            ->whereIn('order_id', $orderIds)
            ->get(['order_id', 'party_type', 'commission_amount', 'settled_amount', 'is_settled', 'payout_id'])
            ->groupBy('order_id');

        $orders = DB::table('pos_orders')
            ->whereIn('id', $orderIds)
            ->get(['id', 'uuid', 'receipt_number', 'opened_at', 'closed_at', 'grand_total'])
            ->keyBy('id');

        // ALL non-failed tenders — the verification workspace shows how each sale
        // was paid (card / cash / bank_pos chips) and groups by the device that
        // rang it. Cash tenders snapshot device/terminal at sale time too, so a
        // cash-only sale still lands under its device's terminal tab.
        $tendersByOrder = DB::table('pos_payments')
            ->whereIn('order_id', $orderIds)
            ->where('status', '!=', 'failed')
            ->orderBy('id')
            ->get(['order_id', 'method', 'amount', 'device_id', 'terminal_id', 'softpos_auth_code', 'softpos_reference', 'captured_at', 'bank_fee'])
            ->groupBy('order_id');

        $deviceIds = $tendersByOrder->flatten(1)->pluck('device_id')->filter()->unique()->values()->all();
        $deviceNames = $deviceIds === [] ? collect() : DB::table('pos_devices')
            ->whereIn('id', $deviceIds)
            ->pluck('name', 'id');

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
            $settledPlatform = 0;
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
                    $settledPlatform += $settled ?? 0;
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
            $cashBaisas = 0;
            $bankPosBaisas = 0;
            $suggestedBankBaisas = 0;
            $hasCapturedFee = false;
            $tenders = [];
            $cardTerminalId = null;
            $anyTerminalId = null;
            $deviceId = null;
            foreach ($tendersByOrder->get($orderId, collect()) as $t) {
                $method = (string) $t->method;
                // The commission BASE is card money only — cash/bank_pos tenders
                // are displayed but never feed the bank-fee math.
                if ($method === 'card') {
                    $cardBaisas += Money::toBaisas($t->amount);
                    $cardTerminalId ??= $t->terminal_id;
                    // A2 — the actual fee captured from the bank statement at import.
                    if ($t->bank_fee !== null) {
                        $suggestedBankBaisas += Money::toBaisas($t->bank_fee);
                        $hasCapturedFee = true;
                    }
                } elseif ($method === 'cash') {
                    $cashBaisas += Money::toBaisas($t->amount);
                } elseif ($method === 'bank_pos') {
                    $bankPosBaisas += Money::toBaisas($t->amount);
                }
                $anyTerminalId ??= $t->terminal_id;
                $deviceId ??= $t->device_id !== null ? (int) $t->device_id : null;
                $tenders[] = [
                    'method' => $method,
                    'amount' => number_format((float) $t->amount, 3, '.', ''),
                    'terminal_id' => $t->terminal_id,
                    'auth_code' => $t->softpos_auth_code,
                    'reference' => $t->softpos_reference,
                    'captured_at' => $t->captured_at,
                ];
            }

            // The terminal that rang this sale — snapshotted onto the payment at
            // sale time, so it is historically accurate even if the device is
            // later reassigned a new terminal id. Card tender's terminal wins (it
            // is what the bank statement references); a cash-only sale falls back
            // to its device's terminal so it still groups under the right tab.
            $terminalId = $cardTerminalId ?? $anyTerminalId;

            $out[] = [
                'order_uuid' => (string) $order->uuid,
                'receipt_number' => $order->receipt_number,
                'occurred_at' => $order->closed_at ?? $order->opened_at,
                'grand_total' => number_format((float) $order->grand_total, 3, '.', ''),
                'terminal_id' => $terminalId,
                'device_name' => $deviceId !== null ? ($deviceNames[$deviceId] ?? null) : null,
                'card_amount' => Money::toOmr($cardBaisas),
                'cash_amount' => Money::toOmr($cashBaisas),
                'bank_pos_amount' => Money::toOmr($bankPosBaisas),
                'roundup' => Money::toOmr(Money::toBaisas($roundupByOrder[$orderId] ?? 0)),
                'estimated_bank' => Money::toOmr($estBank),
                // A2 — the actual fee captured from the bank statement (null when
                // none was imported); the reconcile screen pre-fills from it.
                'suggested_bank' => $hasCapturedFee ? Money::toOmr($suggestedBankBaisas) : null,
                'estimated_platform' => Money::toOmr($estPlatform),
                'estimated_merchant_net' => Money::toOmr($estMerchant),
                // A bank fee to match → CARD. Cash sales (no bank cut) are
                // review-only: nothing to reconcile, and they never gate a payout.
                'needs_reconciliation' => $estBank > 0,
                'is_settled' => $isSettled,
                'is_paid_out' => $isPaidOut,
                'settled_bank' => $isSettled ? Money::toOmr($settledBank) : null,
                'settled_platform' => $isSettled ? Money::toOmr($settledPlatform) : null,
                'settled_merchant_net' => $isSettled ? Money::toOmr($settledMerchant) : null,
                'tenders' => $tenders,
            ];
        }

        // Chronological — matches how a bank statement is read.
        usort($out, static fn (array $x, array $y): int => (string) ($x['occurred_at'] ?? '') <=> (string) ($y['occurred_at'] ?? ''));

        return $out;
    }
}
