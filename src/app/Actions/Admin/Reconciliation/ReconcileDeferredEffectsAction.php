<?php

declare(strict_types=1);

namespace App\Actions\Admin\Reconciliation;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Branch;
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
 *   - forwards any round-up donation rows still lacking forwarded_at,
 *     BEST-EFFORT and outside the DB transaction — a forwarding failure
 *     never rolls back the settlement; the row stays unforwarded for a
 *     retry and is surfaced in the result;
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
        $settled = [];
        $stillPending = [];
        $commissionsRecorded = 0;
        $donationsForwarded = 0;
        $forwardFailures = [];

        $orders = Order::query()->whereIn('id', array_values(array_unique($orderIds)))->get();

        foreach ($orders as $order) {
            // Effects fire only once the WHOLE order is confirmed: a split
            // tender with another pending half keeps everything deferred.
            $hasPending = Payment::query()
                ->where('order_id', $order->id)
                ->where('pending_reconciliation', true)
                ->exists();
            if ($hasPending) {
                $stillPending[] = (int) $order->id;

                continue;
            }

            $commissionIds = DB::transaction(
                fn (): array => $this->recordCommission($order),
            );
            if ($commissionIds !== []) {
                $commissionsRecorded++;
            }

            // Charity forwarding AFTER the commission committed: HTTP must
            // never run inside (nor roll back) the DB transaction.
            [$forwardedIds, $failures] = $this->forwardDonations($order);
            $donationsForwarded += count($forwardedIds);
            foreach ($failures as $failure) {
                $forwardFailures[] = $failure;
            }

            $firedSomething = $commissionIds !== [] || $forwardedIds !== [] || $failures !== [];
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
                        'roundup_donations_forwarded' => $forwardedIds,
                        'roundup_forward_failures' => array_column($failures, 'donation_id'),
                    ],
                ));
            }

            $settled[] = (int) $order->id;
        }

        return [
            'orders_settled' => $settled,
            'orders_still_pending' => $stillPending,
            'commissions_recorded' => $commissionsRecorded,
            'donations_forwarded' => $donationsForwarded,
            'donation_forward_failures' => $forwardFailures,
        ];
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
                $device = Device::query()->find($payment->device_id);
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
     * Forward every not-yet-forwarded round-up of the order, stamping
     * forwarded_at on success. Best-effort per donation.
     *
     * @return array{0: list<int>, 1: list<array{order_id: int, donation_id: int}>}
     */
    private function forwardDonations(Order $order): array
    {
        $forwarded = [];
        $failures = [];

        $donations = RoundupDonation::query()
            ->where('order_id', $order->id)
            ->whereNull('forwarded_at')
            ->get();

        foreach ($donations as $donation) {
            // Device + branch snapshots from the donation row (sale-time
            // facts), matching what pos_api would have sent at record time.
            $device = Device::query()->find($donation->device_id);
            $branch = Branch::query()->find($donation->branch_id);

            // The admin approval confirms the money arrived, so the round-up
            // settles as 'success' — matching pos_api's settled path and
            // overriding the 'pending' it was recorded with at pay time.
            $ok = $device !== null && $this->forwardCharityDonation->forward(
                $device,
                $branch,
                (string) $donation->amount,
                $donation->bank_response,
                'success',
            );

            if ($ok) {
                $donation->forceFill(['forwarded_at' => now(), 'status' => 'success'])->save();
                $forwarded[] = (int) $donation->id;
            } else {
                $failures[] = ['order_id' => (int) $order->id, 'donation_id' => (int) $donation->id];
            }
        }

        return [$forwarded, $failures];
    }
}
