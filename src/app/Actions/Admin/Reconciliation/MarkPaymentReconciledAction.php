<?php

declare(strict_types=1);

namespace App\Actions\Admin\Reconciliation;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Payment;
use App\Models\User;

/**
 * P-F7 — the ONE per-payment "settled" flip, extracted from
 * {@see \App\Actions\Admin\ReconcilePaymentsAction} so the bank-file
 * matching tool and the Pending Reconciliation approval queue stay
 * mechanically identical: confirm the tender as success, clear
 * pending_reconciliation (it leaves the queue), stamp reconciled_at +
 * the acting admin, and audit 'payment.reconciled'.
 */
final readonly class MarkPaymentReconciledAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    public function handle(Payment $payment, ?User $actor = null): void
    {
        $before = [
            'status' => $payment->status instanceof \BackedEnum ? $payment->status->value : (string) $payment->status,
            'pending_reconciliation' => (bool) $payment->pending_reconciliation,
            'reconciled_at' => optional($payment->reconciled_at)->toIso8601String(),
        ];

        $payment->forceFill([
            'status' => 'success',
            'pending_reconciliation' => false,
            'reconciled_by_admin_id' => $actor?->id,
            'reconciled_at' => now(),
        ])->save();

        $this->writeAuditLog->handle(new AuditLogData(
            event: 'payment.reconciled',
            actorUserId: $actor?->id,
            auditableType: Payment::class,
            auditableId: $payment->id,
            oldValues: $before,
            newValues: [
                'status' => 'success',
                'pending_reconciliation' => false,
                'reconciled_at' => optional($payment->reconciled_at)->toIso8601String(),
            ],
        ));
    }
}
