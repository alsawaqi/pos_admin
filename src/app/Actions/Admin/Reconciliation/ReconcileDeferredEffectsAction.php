<?php

declare(strict_types=1);

namespace App\Actions\Admin\Reconciliation;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\OrderStatus;
use App\Models\Device;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RoundupDonation;
use App\Models\SaleCommission;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * P-F7 — the deferred money effects of a sale whose tender(s) sat in
 * pending_reconciliation, fired once the admin confirms the money arrived.
 *
 * pos_api's PayOrderHandler SKIPS two things when any tender is pending:
 *   (1) the per-sale commission split (platform/bank/merchant), and
 *   (2) the charity ROUND-UP forwarding (DonationRecordHandler leaves
 *       pos_roundup_donations.forwarded_at NULL).
 * Inventory consumption and loyalty earn deliberately still fire at pay
 * time (the goods left the shop; points are clawed back by voids) — only
 * the MONEY effects wait for confirmation.
 *
 * This action is the SINGLE shared code path for both admin settle routes
 * (the Pending Reconciliation approval queue AND the bank-file matching
 * tool), so the two can never drift. Per order:
 *   - skipped while ANY tender is still pending_reconciliation;
 *   - records the commission split IF the order has none yet (idempotent —
 *     replays / double-clicks safe), computing cardBaisas / giftBaisas from
 *     the order's now-confirmed tenders exactly like PayOrderHandler does;
 *   - commits eligible round-ups as success/unforwarded with the local money
 *     effects, then forwards them under fresh order and donation locks. The
 *     stable UUID makes crash recovery idempotent; failed requests remain
 *     eligible for the hourly retry and are surfaced in the result;
 *   - audits 'order.reconciliation_approved' with the money summary.
 */
final readonly class ReconcileDeferredEffectsAction
{
    public function __construct(
        private RecordSaleCommissionAction $recordSaleCommission,
        private ForwardCharityDonationAction $forwardCharityDonation,
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @param  list<int>  $orderIds
     * @param  bool  $alwaysAudit  true (approval queue) ⇒ every settled order
     *                             gets an 'order.reconciliation_approved' row,
     *                             even when no effect fired (no profile / no
     *                             round-up) — the approval DECISION is the
     *                             record. false (bank-file commit, which also
     *                             sweeps never-pending orders) ⇒ audit only
     *                             when a deferred effect actually fired.
     * @return array{
     *     orders_settled: list<int>,
     *     orders_still_pending: list<int>,
     *     commissions_recorded: int,
     *     donations_forwarded: int,
     *     donation_forward_failures: list<array{order_id: int, donation_id: int}>,
     * }
     */
    public function handle(array $orderIds, ?User $actor = null, bool $alwaysAudit = false): array
    {
        $results = [];

        foreach (array_values(array_unique(array_map('intval', $orderIds))) as $orderId) {
            $result = DB::transaction(function () use ($orderId, $actor, $alwaysAudit): ?array {
                // The order is the shared serialization root for admin
                // approval, bank reconciliation, retry forwarding, and
                // order.void. Every path acquires it before child rows.
                $order = Order::query()
                    ->whereKey($orderId)
                    ->lockForUpdate()
                    ->first();

                if ($order === null || $this->isVoid($order)) {
                    return null;
                }

                return $this->prepareLockedOrder($order, $actor, $alwaysAudit);
            });

            if ($result !== null) {
                $results[] = $this->finishPrepared($result);
            }
        }

        return $this->combine($results);
    }

    /**
     * Apply every local deferred effect for an order whose row is already
     * locked by the caller's active transaction. Callers acquire the order
     * before locking or changing payments, matching order.void.
     *
     * This method performs no external I/O. It durably queues eligible
     * donations as success/unforwarded in the same transaction as the local
     * effects and decision audit; the caller invokes finishPrepared only
     * after that transaction returns.
     *
     * @return array{
     *     orders_settled: list<int>,
     *     orders_still_pending: list<int>,
     *     commissions_recorded: int,
     *     donations_forwarded: int,
     *     donation_forward_failures: list<array{order_id: int, donation_id: int}>,
     *     donations_to_forward: list<array{order_id: int, donation_id: int}>,
     * }
     */
    public function prepareLockedOrder(Order $order, ?User $actor = null, bool $alwaysAudit = false): array
    {
        if ($this->isVoid($order)) {
            return [...$this->combine([]), 'donations_to_forward' => []];
        }

        // Effects fire only once the WHOLE order is confirmed: a split
        // tender with another pending half keeps everything deferred.
        $hasPending = Payment::query()
            ->where('order_id', $order->id)
            ->where('pending_reconciliation', true)
            ->exists();
        if ($hasPending) {
            return [
                'orders_settled' => [],
                'orders_still_pending' => [(int) $order->id],
                'commissions_recorded' => 0,
                'donations_forwarded' => 0,
                'donation_forward_failures' => [],
                'donations_to_forward' => [],
            ];
        }

        $commissionIds = $this->recordCommission($order);
        $donationsToForward = $this->queueDonations($order);
        $firedSomething = $commissionIds !== [] || $donationsToForward !== [];
        if ($alwaysAudit || $firedSomething) {
            $this->writeAuditLog->handle(new AuditLogData(
                event: 'order.reconciliation_approved',
                actorUserId: $actor?->id,
                companyId: (int) $order->company_id,
                branchId: (int) $order->branch_id,
                auditableType: Order::class,
                auditableId: (int) $order->id,
                newValues: [
                    'grand_total' => (string) $order->grand_total,
                    'sale_commission_ids' => $commissionIds,
                    'roundup_donations_queued' => array_column($donationsToForward, 'donation_id'),
                    'roundup_donations_forwarded' => [],
                    'roundup_forward_failures' => [],
                ],
            ));
        }

        return [
            'orders_settled' => [(int) $order->id],
            'orders_still_pending' => [],
            'commissions_recorded' => $commissionIds === [] ? 0 : 1,
            'donations_forwarded' => 0,
            'donation_forward_failures' => [],
            'donations_to_forward' => $donationsToForward,
        ];
    }

    /**
     * Run the bounded external forwards only after the caller's local
     * settlement transaction commits. Each donation is revalidated while
     * holding fresh order then donation locks, so a concurrent void wins
     * cleanly and a crash leaves a durable success/unforwarded retry row.
     *
     * @param  array{
     *     orders_settled: list<int>,
     *     orders_still_pending: list<int>,
     *     commissions_recorded: int,
     *     donations_forwarded: int,
     *     donation_forward_failures: list<array{order_id: int, donation_id: int}>,
     *     donations_to_forward: list<array{order_id: int, donation_id: int}>,
     * }  $prepared
     * @return array{
     *     orders_settled: list<int>,
     *     orders_still_pending: list<int>,
     *     commissions_recorded: int,
     *     donations_forwarded: int,
     *     donation_forward_failures: list<array{order_id: int, donation_id: int}>,
     * }
     */
    public function finishPrepared(array $prepared): array
    {
        $forwarded = 0;
        $failures = [];

        foreach ($prepared['donations_to_forward'] as $candidate) {
            $result = $this->forwardPreparedDonation(
                $candidate['order_id'],
                $candidate['donation_id'],
            );

            if ($result === 'forwarded') {
                $forwarded++;
            } elseif ($result === 'failed') {
                $failures[] = $candidate;
            }
        }

        unset($prepared['donations_to_forward']);
        $prepared['donations_forwarded'] = $forwarded;
        $prepared['donation_forward_failures'] = $failures;

        return $prepared;
    }

    /**
     * @param  list<array{
     *     orders_settled: list<int>,
     *     orders_still_pending: list<int>,
     *     commissions_recorded: int,
     *     donations_forwarded: int,
     *     donation_forward_failures: list<array{order_id: int, donation_id: int}>,
     * }>  $results
     * @return array{
     *     orders_settled: list<int>,
     *     orders_still_pending: list<int>,
     *     commissions_recorded: int,
     *     donations_forwarded: int,
     *     donation_forward_failures: list<array{order_id: int, donation_id: int}>,
     * }
     */
    public function combine(array $results): array
    {
        $combined = [
            'orders_settled' => [],
            'orders_still_pending' => [],
            'commissions_recorded' => 0,
            'donations_forwarded' => 0,
            'donation_forward_failures' => [],
        ];

        foreach ($results as $result) {
            $combined['orders_settled'] = [...$combined['orders_settled'], ...$result['orders_settled']];
            $combined['orders_still_pending'] = [...$combined['orders_still_pending'], ...$result['orders_still_pending']];
            $combined['commissions_recorded'] += $result['commissions_recorded'];
            $combined['donations_forwarded'] += $result['donations_forwarded'];
            $combined['donation_forward_failures'] = [
                ...$combined['donation_forward_failures'],
                ...$result['donation_forward_failures'],
            ];
        }

        return $combined;
    }

    /**
     * Record the deferred commission split. Mirrors PayOrderHandler's
     * accumulation: cardBaisas = the order's 'card' tenders (now confirmed),
     * giftBaisas = its 'gift' tenders; failed tenders never count. The
     * RecordSaleCommissionAction twin is itself idempotent (no rows are
     * written when the order already has a breakdown).
     *
     * @return array<int, int> ids of the created sale-commission rows
     */
    private function recordCommission(Order $order): array
    {
        // Cheap pre-check so settled orders skip the tender math entirely.
        if (SaleCommission::query()->where('order_id', $order->id)->exists()) {
            return [];
        }

        $payments = Payment::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get();

        $cardBaisas = 0;
        $giftBaisas = 0;
        $device = null;
        foreach ($payments as $payment) {
            $method = $payment->method instanceof \BackedEnum ? $payment->method->value : (string) $payment->method;
            $status = $payment->status instanceof \BackedEnum ? $payment->status->value : (string) $payment->status;

            // P-F5 — ONLY the 'card' method (our Soft POS) accumulates into
            // the bank-commission base; gift is money never collected.
            // Exactly PayOrderHandler's rule, applied to the stored tenders.
            if ($method === 'card' && $status !== 'failed') {
                $cardBaisas += Money::toBaisas($payment->amount);
            }
            if ($method === 'gift' && $status !== 'failed') {
                $giftBaisas += Money::toBaisas($payment->amount);
            }

            if ($device === null && $payment->device_id !== null) {
                $device = Device::withTrashed()->find($payment->device_id);
            }
        }

        if ($device === null) {
            // pos_sale_commissions.device_id is NOT NULL — without the
            // device snapshot (pre-P-F4 history) the split cannot be
            // attributed; leave it for pos_api's idempotent path. In
            // practice every pending tender carries device_id.
            return [];
        }

        return $this->recordSaleCommission->record(
            $order,
            $device,
            $cardBaisas,
            $giftBaisas,
            $payments->first()?->id !== null ? (int) $payments->first()->id : null,
            null, // no device sync event behind an admin approval
        );
    }

    /**
     * Mark every eligible donation as settled and return its durable identity.
     * This local state is committed with the payment flips, commission, and
     * decision audit before any external request starts.
     *
     * @return list<array{order_id: int, donation_id: int}>
     */
    private function queueDonations(Order $order): array
    {
        $queued = [];
        $donations = RoundupDonation::query()
            ->where('order_id', $order->id)
            ->whereNull('forwarded_at')
            ->whereNotIn('status', ['rejected', 'void'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($donations as $donation) {
            if ((string) $donation->status !== 'success') {
                $donation->forceFill(['status' => 'success'])->save();
            }

            $queued[] = [
                'order_id' => (int) $order->id,
                'donation_id' => (int) $donation->id,
            ];
        }

        return $queued;
    }

    /**
     * @return 'forwarded'|'failed'|'stale'
     */
    private function forwardPreparedDonation(int $orderId, int $donationId): string
    {
        return DB::transaction(function () use ($orderId, $donationId): string {
            $order = Order::query()
                ->whereKey($orderId)
                ->lockForUpdate()
                ->first();

            if ($order === null || $this->isVoid($order)) {
                return 'stale';
            }

            $donation = RoundupDonation::query()
                ->whereKey($donationId)
                ->where('order_id', $order->id)
                ->whereNull('forwarded_at')
                ->where('status', 'success')
                ->lockForUpdate()
                ->first();

            if ($donation === null) {
                return 'stale';
            }

            if (! $this->forwardCharityDonation->forwardSnapshot($donation)) {
                return 'failed';
            }

            $donation->forceFill(['forwarded_at' => now()])->save();

            return 'forwarded';
        });
    }

    private function isVoid(Order $order): bool
    {
        return $order->getAttribute('status') === OrderStatus::Void;
    }
}
