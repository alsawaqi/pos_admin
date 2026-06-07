<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mark the reconciliation-matched card payments as settled: clears
 * pending_reconciliation (they leave the queue), stamps reconciled_at +
 * the acting admin, and confirms the tender as success. Each is audited.
 */
final readonly class ReconcilePaymentsAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @param  list<int>  $paymentIds
     * @return array{reconciled: int, payment_ids: list<int>}
     */
    public function handle(array $paymentIds, ?User $actor = null): array
    {
        return DB::transaction(function () use ($paymentIds, $actor): array {
            $reconciledIds = [];

            $payments = Payment::query()->whereIn('id', $paymentIds)->get();

            foreach ($payments as $payment) {
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

                $reconciledIds[] = (int) $payment->id;
            }

            return ['reconciled' => count($reconciledIds), 'payment_ids' => $reconciledIds];
        });
    }
}
