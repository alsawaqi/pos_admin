<?php

declare(strict_types=1);

namespace App\Actions\Admin\Reconciliation;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * P-F7 — approve pending-reconciliation ORDERS from the daily admin queue:
 * the bank file confirmed the force-recorded Soft POS money actually
 * arrived.
 *
 * Per order, inside one transaction, every pending tender is flipped via
 * the SAME mechanics as the bank-file tool ({@see MarkPaymentReconciledAction}
 * — success + pending_reconciliation=false + reconciled_by/at + a
 * 'payment.reconciled' audit). Then the deferred money effects fire through
 * the shared {@see ReconcileDeferredEffectsAction}: the commission split is
 * recorded exactly once (idempotent) and any unforwarded round-up donations
 * go to the charity app (best-effort — a forwarding failure never rolls
 * back the approval; it is surfaced in the result for a retry).
 *
 * This is the twin trigger of pos_api PayOrderHandler's P-F7 skip: what the
 * device pay path deferred, this approval records.
 */
final readonly class ApprovePendingReconciliationAction
{
    public function __construct(
        private MarkPaymentReconciledAction $markPaymentReconciled,
        private ReconcileDeferredEffectsAction $deferredEffects,
    ) {}

    /**
     * @param  list<int>  $orderIds
     * @return array{
     *     orders_approved: int,
     *     payments_reconciled: int,
     *     effects: array{
     *         orders_settled: list<int>,
     *         orders_still_pending: list<int>,
     *         commissions_recorded: int,
     *         donations_forwarded: int,
     *         donation_forward_failures: list<array{order_id: int, donation_id: int}>,
     *     },
     * }
     */
    public function handle(array $orderIds, ?User $actor = null): array
    {
        $paymentsReconciled = 0;
        $approvedOrderIds = [];

        $orders = Order::query()->whereIn('id', array_values(array_unique($orderIds)))->get();

        foreach ($orders as $order) {
            $flipped = DB::transaction(function () use ($order, $actor): int {
                $pending = Payment::query()
                    ->where('order_id', $order->id)
                    ->where('pending_reconciliation', true)
                    ->get();

                foreach ($pending as $payment) {
                    $this->markPaymentReconciled->handle($payment, $actor);
                }

                return $pending->count();
            });

            $paymentsReconciled += $flipped;
            $approvedOrderIds[] = (int) $order->id;
        }

        // Deferred money effects AFTER the flips committed — the shared code
        // path with the bank-file route (commission idempotent; charity
        // forwarding best-effort + retryable, so it must not sit inside the
        // flip transaction). alwaysAudit: an explicit approval decision is
        // always worth an 'order.reconciliation_approved' row.
        $effects = $this->deferredEffects->handle($approvedOrderIds, $actor, alwaysAudit: true);

        return [
            'orders_approved' => count($approvedOrderIds),
            'payments_reconciled' => $paymentsReconciled,
            'effects' => $effects,
        ];
    }
}
