<?php

declare(strict_types=1);

namespace App\Actions\Admin\Payouts;

use App\Models\Payout;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v2 #17 (Phase B) — create a pending payout for a merchant + period.
 *
 * Claims the period's still-unclaimed merchant-commission rows (party_type=
 * 'merchant', payout_id NULL) by stamping payout_id on them, so the same
 * earnings can never be paid twice. The claim is CHANNEL-FILTERED (mixed-
 * tender apportionment): only card-channel residuals — money the platform
 * actually holds — plus legacy 'all' rows under the original pure-cash
 * exclusion. Voided orders are excluded outright. net_amount = Σ claimed
 * rows; the deduction breakdown (gross/platform/bank/other) is snapshot from
 * the SAME orders' card+legacy channel rows, so the statement describes
 * exactly the money that flowed through THIS payout. Throws if there's
 * nothing to pay in the window.
 */
final class CreatePayoutAction
{
    public function handle(int $companyId, CarbonInterface $from, CarbonInterface $to, ?int $actorId, ?int $branchId = null): Payout
    {
        return DB::transaction(function () use ($companyId, $from, $to, $actorId, $branchId): Payout {
            // Unsettled merchant rows in the window (lock so a concurrent payout
            // can't claim the same rows). Optionally scoped to one branch (the
            // daily per-branch payout flow).
            $merchantRows = DB::table('pos_sale_commissions')
                ->where('company_id', $companyId)
                ->whereBetween('occurred_at', [$from, $to])
                ->where('party_type', 'merchant')
                ->whereNull('payout_id')
                // A VOIDED order must never be claimed: the order-level void
                // guard keeps a claimed order's rows alive for statement
                // integrity, so the surviving unclaimed rows of a voided sale
                // would otherwise stay claim targets forever (billing a
                // refunded sale / paying out refunded card money).
                ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('pos_orders')
                    ->whereColumn('pos_orders.id', 'pos_sale_commissions.order_id')
                    ->where('pos_orders.status', 'void'))
                ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
                // A payout pays the merchant only the CARD money the platform
                // holds. Channel-split rows make this exact: claim ONLY the
                // card-channel residual — a mixed order's cash_bank residual is
                // the drawer money the merchant already has (its commission is
                // billed via invoices; paying it out was the mixed-order leak).
                // LEGACY 'all' rows (pre-split history + the rollout window
                // while old recorders still write them) keep the original
                // rule: exclude pure cash/bank_pos orders (has a cash/bank_pos
                // tender and no card tender), whole-order residual otherwise.
                ->where(function ($q): void {
                    $q->where('channel', 'card')
                        ->orWhere(function ($legacy): void {
                            $legacy->where('channel', 'all')
                                ->whereNot(function ($q): void {
                                    $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('pos_payments as heldpay')
                                        ->whereColumn('heldpay.order_id', 'pos_sale_commissions.order_id')
                                        ->whereIn('heldpay.method', ['cash', 'bank_pos'])
                                        ->where('heldpay.status', '<>', 'failed'))
                                        ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('pos_payments as cardpay')
                                            ->whereColumn('cardpay.order_id', 'pos_sale_commissions.order_id')
                                            ->where('cardpay.method', 'card')
                                            ->where('cardpay.status', '<>', 'failed'));
                                });
                        });
                })
                ->lockForUpdate()
                ->get(['id', 'order_id', 'commission_amount', 'settled_amount']);

            if ($merchantRows->isEmpty()) {
                throw new RuntimeException('No unsettled earnings for this merchant in the selected period.');
            }

            $rowIds = $merchantRows->pluck('id')->all();
            $orderIds = $merchantRows->pluck('order_id')->unique()->values()->all();

            // Reconcile-before-payout guard (matches the UI gate + the workflow
            // intent): refuse if any order being claimed still has an UNRECONCILED
            // card portion — a 'bank' row with a fee, not yet settled against the
            // bank statement. Paying out now would freeze the ESTIMATE (a paid
            // sale drops off the worklist). Cash sales (no bank fee) are exempt.
            $unreconciledCard = DB::table('pos_sale_commissions')
                ->whereIn('order_id', $orderIds)
                ->where('party_type', 'bank')
                ->where('commission_amount', '>', 0)
                ->where('is_settled', false)
                ->exists();
            if ($unreconciledCard) {
                throw new RuntimeException('Reconcile all card sales against the bank statement before paying out.');
            }
            // The payable is the SETTLED net where a card sale has been
            // reconciled against the bank's actual fee, else the estimate
            // (unchanged for cash sales, whose estimate is already final).
            $net = (float) $merchantRows->sum(static fn ($r): float => (float) ($r->settled_amount ?? $r->commission_amount));

            // Deduction snapshot from the claimed orders' party rows — settled
            // where reconciled, estimate otherwise. Channel-consistent with the
            // claim: card + legacy rows only, so a mixed order's cash-channel
            // commission (billed via invoice, not withheld here) never appears
            // in this statement and gross == what flowed through THIS payout.
            $byParty = DB::table('pos_sale_commissions')
                ->whereIn('order_id', $orderIds)
                ->whereIn('channel', ['card', 'all'])
                ->selectRaw('party_type, COALESCE(SUM(COALESCE(settled_amount, commission_amount)), 0) AS total')
                ->groupBy('party_type')
                ->pluck('total', 'party_type');
            $amt = static fn (string $p): float => (float) ($byParty[$p] ?? 0);
            $gross = $amt('platform') + $amt('bank') + $amt('other') + $amt('merchant');

            $payout = Payout::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'period_from' => $from,
                'period_to' => $to,
                'status' => Payout::STATUS_PENDING,
                'gross_amount' => self::fmt($gross),
                'platform_amount' => self::fmt($amt('platform')),
                'bank_amount' => self::fmt($amt('bank')),
                'other_amount' => self::fmt($amt('other')),
                'net_amount' => self::fmt($net),
                'sales_count' => count($orderIds),
                'created_by_user_id' => $actorId,
            ]);

            // Claim the merchant rows for this payout (double-pay guard).
            DB::table('pos_sale_commissions')
                ->whereIn('id', $rowIds)
                ->update(['payout_id' => $payout->id]);

            return $payout->fresh();
        });
    }

    private static function fmt(float $omr): string
    {
        return number_format($omr, 3, '.', '');
    }
}
