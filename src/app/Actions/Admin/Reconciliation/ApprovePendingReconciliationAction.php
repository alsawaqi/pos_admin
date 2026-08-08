<?php

declare(strict_types=1);

namespace App\Actions\Admin\Reconciliation;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * P-F7 — approve pending-reconciliation ORDERS from the daily admin queue:
 * the bank file confirmed the force-recorded Soft POS money actually
 * arrived.
 *
 * Per order, one transaction flips every pending tender and atomically records
 * the deferred commission, settled donation queue, and decision audit through
 * {@see ReconcileDeferredEffectsAction}. Only after that local commit returns
 * are queued donations sent to charity under fresh order and donation locks.
 * A crash or failed request leaves success/unforwarded rows for the hourly
 * UUID-idempotent retry, so external availability cannot roll back the sale
 * approval while forward-vs-void remains serialized.
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
        $effectResults = [];

        foreach (array_values(array_unique(array_map('intval', $orderIds))) as $orderId) {
            [$flipped, $effects] = DB::transaction(function () use ($orderId, $actor): array {
                // order.void takes this same lock before donations/payments.
                // It is the serialization root for the entire decision.
                $order = Order::query()
                    ->whereKey($orderId)
                    ->lockForUpdate()
                    ->first();

                if ($order === null || $this->isVoid($order)) {
                    return [0, null];
                }

                $pending = Payment::query()
                    ->where('order_id', $order->id)
                    ->where('pending_reconciliation', true)
                    ->lockForUpdate()
                    ->get();

                if ($pending->isEmpty()) {
                    return [0, null];
                }

                foreach ($pending as $payment) {
                    $this->markPaymentReconciled->handle($payment, $actor);
                }

                // Payment flips, their audits, commissions, queued donation
                // state, and the order decision audit are one local commit.
                // The bounded external request starts only after this returns.
                $effects = $this->deferredEffects->prepareLockedOrder(
                    $order,
                    $actor,
                    alwaysAudit: true,
                );

                return [$pending->count(), $effects];
            });

            if ($effects !== null) {
                $effects = $this->deferredEffects->finishPrepared($effects);
            }

            $paymentsReconciled += $flipped;
            if ($flipped > 0) {
                $approvedOrderIds[] = $orderId;
            }
            if ($effects !== null) {
                $effectResults[] = $effects;
            }
        }

        return [
            'orders_approved' => count($approvedOrderIds),
            'payments_reconciled' => $paymentsReconciled,
            'effects' => $this->deferredEffects->combine($effectResults),
        ];
    }

    private function isVoid(Order $order): bool
    {
        return $order->getAttribute('status') === OrderStatus::Void;
    }
}
