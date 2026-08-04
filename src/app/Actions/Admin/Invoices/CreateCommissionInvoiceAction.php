<?php

declare(strict_types=1);

namespace App\Actions\Admin\Invoices;

use App\Models\CommissionInvoice;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase B — issue a commission invoice for a merchant + period (the reverse of
 * {@see \App\Actions\Admin\Payouts\CreatePayoutAction}).
 *
 * Claims the period's still-un-invoiced platform + other commission rows
 * (party_type IN ('platform','other'), invoice_id NULL) of merchant-held money
 * by stamping invoice_id on them, so the same commission can never be billed
 * twice. total_owed = Σ those rows; the gross/merchant snapshot comes from the
 * SAME orders' cash-channel party rows, so the bill is self-contained. Throws
 * if there's nothing un-invoiced in the window.
 *
 * MIXED-TENDER APPORTIONMENT: channel-split rows carry channel='cash_bank' for
 * exactly the merchant-held slice — of a pure cash order OR the cash slice of
 * a MIXED card+cash order (whose card slice rides the payout). Both are billed
 * here now; the paired payout change stopped paying the cash slice out, so the
 * commission is collected exactly once. LEGACY 'all' rows (pre-split history)
 * keep the original rule: only PURE cash/bank_pos orders (cash/bank_pos tender
 * AND no card tender).
 *
 * VERIFIED-FIRST (the admin methodology): only sales the admin has verified
 * one-by-one in the Sales workspace (is_settled = true — bank fee 0, platform
 * confirmed/edited) are billable. Unverified cash sales stay out of the invoice
 * until verified, so the bill only ever contains figures a human signed off.
 * The billed amount is the VERIFIED figure (settled_amount, falling back to the
 * estimate for legacy rows verified before per-row stamping).
 */
final class CreateCommissionInvoiceAction
{
    /** Non-failed tender methods that mean "money the merchant collected and holds". */
    private const MERCHANT_HELD_METHODS = ['cash', 'bank_pos'];

    public function handle(int $companyId, CarbonInterface $from, CarbonInterface $to, ?int $actorId, ?int $branchId = null): CommissionInvoice
    {
        return DB::transaction(function () use ($companyId, $from, $to, $actorId, $branchId): CommissionInvoice {
            // Un-invoiced platform/other rows of VERIFIED pure cash/bank_pos
            // orders in the window (lock so a concurrent invoice can't claim the
            // same rows). Verified-first: unverified sales are not billable.
            $rows = DB::table('pos_sale_commissions')
                ->where('company_id', $companyId)
                ->whereBetween('occurred_at', [$from, $to])
                ->whereIn('party_type', ['platform', 'other'])
                ->whereNull('invoice_id')
                ->where('is_settled', true)
                // The billed figure is the VERIFIED one — claim on it too, so the
                // claimed row set is exactly the billed row set (a commission
                // verified UP from a 0 estimate is claimable; one verified DOWN
                // to 0 is not billed).
                ->whereRaw('COALESCE(settled_amount, commission_amount) > 0')
                // A VOIDED order must never be claimed: the order-level void
                // guard keeps a claimed order's rows alive for statement
                // integrity, so the surviving unclaimed rows of a voided sale
                // would otherwise stay claim targets forever (billing a
                // refunded sale / paying out refunded card money).
                ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('pos_orders')
                    ->whereColumn('pos_orders.id', 'pos_sale_commissions.order_id')
                    ->where('pos_orders.status', 'void'))
                ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
                // Cash-channel rows are billable by construction (pure cash
                // AND the cash slice of mixed orders); legacy 'all' rows keep
                // the original pure-cash-only predicate.
                ->where(function ($q): void {
                    $q->where('channel', 'cash_bank')
                        ->orWhere(function ($legacy): void {
                            $legacy->where('channel', 'all')
                                ->whereExists(fn ($s) => $s->select(DB::raw(1))->from('pos_payments as heldpay')
                                    ->whereColumn('heldpay.order_id', 'pos_sale_commissions.order_id')
                                    ->whereIn('heldpay.method', self::MERCHANT_HELD_METHODS)
                                    ->where('heldpay.status', '<>', 'failed'))
                                ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('pos_payments as cardpay')
                                    ->whereColumn('cardpay.order_id', 'pos_sale_commissions.order_id')
                                    ->where('cardpay.method', 'card')
                                    ->where('cardpay.status', '<>', 'failed'));
                        });
                })
                ->lockForUpdate()
                ->get(['id', 'order_id', 'party_type', 'commission_amount']);

            if ($rows->isEmpty()) {
                throw new RuntimeException('No verified un-invoiced cash or bank-POS commission for this merchant in the selected period. Verify the sales in the Sales workspace first.');
            }

            $rowIds = $rows->pluck('id')->all();
            $orderIds = $rows->pluck('order_id')->unique()->values()->all();

            // Snapshot the full split of the billed orders at their VERIFIED
            // figures (settled_amount where stamped, the estimate otherwise).
            // platform + other are exactly the rows being claimed (they are
            // always claimed together per order, so no prior partial invoice can
            // skew this); merchant is what the merchant keeps; gross = collected
            // (bank ≈ 0 on cash/bank_pos).
            // Channel-consistent with the claim: for a mixed order only its
            // cash-channel rows belong on this bill (the card slice is the
            // payout's business). Legacy 'all' rows are whole-order — correct,
            // since legacy billed orders are pure cash by predicate.
            $byParty = DB::table('pos_sale_commissions')
                ->whereIn('order_id', $orderIds)
                ->whereIn('channel', ['cash_bank', 'all'])
                ->selectRaw('party_type, COALESCE(SUM(COALESCE(settled_amount, commission_amount)), 0) AS total')
                ->groupBy('party_type')
                ->pluck('total', 'party_type');
            $amt = static fn (string $p): float => (float) ($byParty[$p] ?? 0);

            $platform = $amt('platform');
            $other = $amt('other');
            $merchant = $amt('merchant');
            $gross = $platform + $amt('bank') + $other + $merchant;
            $totalOwed = $platform + $other;

            // Step 4 — how the billed money was RECEIVED: the statement tells the
            // merchant "you took X in cash and Y on the bank's POS".
            $byMethod = DB::table('pos_payments')
                ->whereIn('order_id', $orderIds)
                ->whereIn('method', self::MERCHANT_HELD_METHODS)
                ->where('status', '<>', 'failed')
                ->selectRaw('method, COALESCE(SUM(amount), 0) AS total')
                ->groupBy('method')
                ->pluck('total', 'method');
            $cashGross = (float) ($byMethod['cash'] ?? 0);
            $bankPosGross = (float) ($byMethod['bank_pos'] ?? 0);

            $invoice = CommissionInvoice::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'period_from' => $from,
                'period_to' => $to,
                'status' => CommissionInvoice::STATUS_ISSUED,
                'gross_amount' => self::fmt($gross),
                'cash_gross' => self::fmt($cashGross),
                'bank_pos_gross' => self::fmt($bankPosGross),
                'platform_amount' => self::fmt($platform),
                'other_amount' => self::fmt($other),
                'merchant_amount' => self::fmt($merchant),
                'total_owed' => self::fmt($totalOwed),
                'sales_count' => count($orderIds),
                'created_by_user_id' => $actorId,
            ]);

            // Claim the platform/other rows for this invoice (double-bill guard).
            DB::table('pos_sale_commissions')
                ->whereIn('id', $rowIds)
                ->update(['invoice_id' => $invoice->id]);

            return $invoice->fresh();
        });
    }

    private static function fmt(float $omr): string
    {
        return number_format($omr, 3, '.', '');
    }
}
