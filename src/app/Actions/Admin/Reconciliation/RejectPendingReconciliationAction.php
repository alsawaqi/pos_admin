<?php

declare(strict_types=1);

namespace App\Actions\Admin\Reconciliation;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * P-F7 — reject a pending-reconciliation order: the bank file shows the
 * force-recorded Soft POS money NEVER arrived.
 *
 * Marks every pending tender status='failed' (pending_reconciliation
 * cleared so it leaves the queue; reconciled_by/at stamped as the DECISION
 * trail — they record who ruled on the tender and when, not that money
 * settled) and audits 'payment.reconciliation_rejected' per payment.
 *
 * DELIBERATE SCOPE: this does NOT auto-void the order. Rejection only
 * records that the money never arrived; the sale itself (inventory already
 * consumed, loyalty already earned) must be voided / re-charged / handled
 * through the normal order flows by the merchant. None of the deferred
 * money effects fire: no commission split, no charity forwarding.
 */
final readonly class RejectPendingReconciliationAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @param  list<int>  $orderIds
     * @return array{orders_rejected: int, payments_failed: int}
     */
    public function handle(array $orderIds, ?User $actor = null): array
    {
        $paymentsFailed = 0;
        $ordersRejected = 0;

        $orders = Order::query()->whereIn('id', array_values(array_unique($orderIds)))->get();

        foreach ($orders as $order) {
            $failed = DB::transaction(function () use ($order, $actor): int {
                $pending = Payment::query()
                    ->where('order_id', $order->id)
                    ->where('pending_reconciliation', true)
                    ->get();

                foreach ($pending as $payment) {
                    $before = [
                        'status' => $payment->status instanceof \BackedEnum ? $payment->status->value : (string) $payment->status,
                        'pending_reconciliation' => (bool) $payment->pending_reconciliation,
                    ];

                    $payment->forceFill([
                        'status' => 'failed',
                        'pending_reconciliation' => false,
                        'reconciled_by_admin_id' => $actor?->id,
                        'reconciled_at' => now(),
                    ])->save();

                    $this->writeAuditLog->handle(new AuditLogData(
                        event: 'payment.reconciliation_rejected',
                        actorUserId: $actor?->id,
                        auditableType: Payment::class,
                        auditableId: $payment->id,
                        oldValues: $before,
                        newValues: [
                            'status' => 'failed',
                            'pending_reconciliation' => false,
                            'reconciled_at' => optional($payment->reconciled_at)->toIso8601String(),
                        ],
                    ));
                }

                return $pending->count();
            });

            if ($failed > 0) {
                $ordersRejected++;
                $paymentsFailed += $failed;
            }
        }

        return ['orders_rejected' => $ordersRejected, 'payments_failed' => $paymentsFailed];
    }
}
