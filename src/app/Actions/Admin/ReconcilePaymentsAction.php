<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Admin\Reconciliation\MarkPaymentReconciledAction;
use App\Actions\Admin\Reconciliation\ReconcileDeferredEffectsAction;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mark the reconciliation-matched card payments as settled: clears
 * pending_reconciliation (they leave the queue), stamps reconciled_at +
 * the acting admin, and confirms the tender as success. Each is audited.
 *
 * P-F7 — the per-payment flip lives in {@see MarkPaymentReconciledAction},
 * shared with the Pending Reconciliation approval queue so both paths stay
 * mechanically identical. The SAME transaction applies every local deferred
 * effect for the affected orders ({@see ReconcileDeferredEffectsAction}) and
 * durably queues charity round-ups. Only after the all-or-nothing bank batch
 * commits are the bounded external forwards attempted under fresh per-order
 * locks, so a charity outage cannot hold or roll back the settlement batch.
 */
final readonly class ReconcilePaymentsAction
{
    public function __construct(
        private MarkPaymentReconciledAction $markPaymentReconciled,
        private ReconcileDeferredEffectsAction $deferredEffects,
    ) {}

    /**
     * @param  list<int>  $paymentIds
     * @param  array<int, mixed>  $feeByPaymentId  payment_id => actual bank fee (A2); absent/null → not captured
     * @return array{reconciled: int, payment_ids: list<int>, effects: array<string, mixed>}
     */
    public function handle(array $paymentIds, ?User $actor = null, array $feeByPaymentId = [], ?string $statementToken = null): array
    {
        $statement = $statementToken === null ? null
            : Cache::get('pos:bank-statement:'.$statementToken);
        if (! is_array($statement) || $actor === null || (int) $statement['actor_id'] !== (int) $actor->id) {
            throw ValidationException::withMessages(['statement_token' => 'Upload the statement again before committing.']);
        }
        $proofs = collect($statement['matched'])->keyBy('payment.id');
        $selectedIds = array_values(array_unique(array_map('intval', $paymentIds)));
        if (count($selectedIds) !== count($paymentIds) || $selectedIds === []
            || array_diff($selectedIds, $proofs->keys()->all()) !== []) {
            throw ValidationException::withMessages(['payment_ids' => 'The statement does not prove every selected payment.']);
        }
        $candidatePayments = Payment::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $paymentIds))))
            ->orderBy('order_id')
            ->orderBy('id')
            ->get(['id', 'order_id']);

        $candidateIds = $candidatePayments
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $orderIds = $candidatePayments
            ->pluck('order_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        [$reconciledIds, $effectResults] = DB::transaction(function () use (
            $orderIds,
            $candidateIds,
            $actor,
            $statement,
            $proofs,
            $selectedIds,
        ): array {
            // Preserve the bank commit's all-or-nothing batch contract while
            // obeying the shared lock hierarchy: every order, in stable id
            // order, is locked before any selected payment.
            $orders = Order::query()
                ->whereIn('id', $orderIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $paymentsByOrder = Payment::query()
                ->whereIn('id', $candidateIds)
                ->orderBy('order_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->groupBy('order_id');

            // Validate the whole locked batch before the first mutation.
            $locked = $paymentsByOrder->flatten();
            if ($locked->count() !== count($selectedIds)) {
                throw ValidationException::withMessages(['payment_ids' => 'A selected payment no longer exists.']);
            }
            foreach ($locked as $payment) {
                $proof = $proofs->get((int) $payment->id);
                $line = $proof['statement'];
                $order = $orders->get($payment->order_id);
                $status = $payment->status instanceof \BackedEnum ? $payment->status->value : $payment->status;
                $method = $payment->method instanceof \BackedEnum ? $payment->method->value : $payment->method;
                $normalize = static fn ($v) => strtoupper(preg_replace('/\s+/', '', trim((string) $v)));
                $start = Carbon::parse($statement['statement_date'], 'Asia/Muscat')->startOfDay()->utc();
                $amount = (float) $payment->amount + ($payment->direction === 'reversal' ? 0 : (float) $payment->roundup_amount);
                if ($order === null || $this->isVoid($order)
                    || (int) $order->company_id !== (int) $proof['payment']['company_id']
                    || $method !== 'card' || $status !== 'pending_reconciliation' || ! $payment->pending_reconciliation
                    || (int) $payment->bank_id !== (int) $statement['bank_id']
                    || empty($payment->terminal_id) || $normalize($payment->terminal_id) !== $normalize($line['terminal_id'])
                    || $normalize($payment->softpos_auth_code) !== $normalize($line['auth_code'])
                    || abs($amount - (float) $line['gross_amount']) >= 0.0005
                    || $payment->captured_at === null || $payment->captured_at->lt($start) || ! $payment->captured_at->lt($start->copy()->addDay())
                    || $line['date'] !== $statement['statement_date']) {
                    throw ValidationException::withMessages(['payment_ids' => 'A payment no longer matches the uploaded statement. Nothing was reconciled.']);
                }
            }
            $ids = [];
            $effects = [];
            foreach ($orderIds as $orderId) {
                $order = $orders->get($orderId);
                if ($order === null || $this->isVoid($order)) {
                    continue;
                }

                $payments = $paymentsByOrder->get($orderId, collect());
                if ($payments->isEmpty()) {
                    continue;
                }

                foreach ($payments as $payment) {
                    $this->markPaymentReconciled->handle($payment, $actor);

                    // A2 — persist the actual bank fee from the statement so the
                    // commission settlement worklist can pre-fill it.
                    $fee = $proofs->get((int) $payment->id)['bank_fee'] ?? null;
                    if ($fee !== null && $fee !== '') {
                        $payment->forceFill(['bank_fee' => $fee])->save();
                    }

                    $ids[] = (int) $payment->id;
                }

                $effects[] = $this->deferredEffects->prepareLockedOrder($order, $actor);
            }

            return [$ids, $effects];
        });

        $effectResults = array_map(
            fn (array $prepared): array => $this->deferredEffects->finishPrepared($prepared),
            $effectResults,
        );

        return [
            'reconciled' => count($reconciledIds),
            'payment_ids' => $reconciledIds,
            'effects' => $this->deferredEffects->combine($effectResults),
        ];
    }

    private function isVoid(Order $order): bool
    {
        return $order->getAttribute('status') === OrderStatus::Void;
    }
}
