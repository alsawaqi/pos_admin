<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Admin\Reconciliation\MarkPaymentReconciledAction;
use App\Actions\Admin\Reconciliation\ReconcileDeferredEffectsAction;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mark the reconciliation-matched card payments as settled: clears
 * pending_reconciliation (they leave the queue), stamps reconciled_at +
 * the acting admin, and confirms the tender as success. Each is audited.
 *
 * P-F7 — the per-payment flip lives in {@see MarkPaymentReconciledAction},
 * shared with the Pending Reconciliation approval queue so both paths stay
 * mechanically identical. After the flips commit, the SAME deferred money
 * effects fire for the affected orders ({@see ReconcileDeferredEffectsAction}:
 * commission split + charity round-up forwarding) — the bank-file route and
 * the approval queue converge on a single code path.
 */
final readonly class ReconcilePaymentsAction
{
    public function __construct(
        private MarkPaymentReconciledAction $markPaymentReconciled,
        private ReconcileDeferredEffectsAction $deferredEffects,
    ) {}

    /**
     * @param  list<int>  $paymentIds
     * @return array{reconciled: int, payment_ids: list<int>, effects: array<string, mixed>}
     */
    public function handle(array $paymentIds, ?User $actor = null): array
    {
        [$reconciledIds, $orderIds] = DB::transaction(function () use ($paymentIds, $actor): array {
            $reconciledIds = [];
            $orderIds = [];

            $payments = Payment::query()->whereIn('id', $paymentIds)->get();

            foreach ($payments as $payment) {
                $this->markPaymentReconciled->handle($payment, $actor);

                $reconciledIds[] = (int) $payment->id;
                $orderIds[] = (int) $payment->order_id;
            }

            return [$reconciledIds, array_values(array_unique($orderIds))];
        });

        // Deferred money effects AFTER the flips committed (charity
        // forwarding is HTTP — it must never run inside, nor roll back,
        // the settle transaction).
        $effects = $this->deferredEffects->handle($orderIds, $actor);

        return [
            'reconciled' => count($reconciledIds),
            'payment_ids' => $reconciledIds,
            'effects' => $effects,
        ];
    }
}
