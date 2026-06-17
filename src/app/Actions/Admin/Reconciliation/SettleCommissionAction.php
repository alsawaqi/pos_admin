<?php

declare(strict_types=1);

namespace App\Actions\Admin\Reconciliation;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\CommissionSettlement;
use App\Models\SaleCommission;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Commission SETTLEMENT — reconcile a merchant's card sales against the bank's
 * ACTUAL fee and finalise the merchant's exact net (estimate → settled).
 *
 * pos_api records each sale's split at order.pay from the merchant's CONFIGURED
 * percents; for a card sale the bank slice is only an ESTIMATE (the real
 * acquirer fee varies). Once the admin knows the actual fee (from the bank
 * statement), they settle the period's unsettled card orders:
 *   - the actual fee is allocated across the orders proportional to each one's
 *     card volume (MDR is a % of the card amount), largest-remainder so the
 *     allocation sums to the entered total to the baisa;
 *   - PASS-THROUGH model: the merchant bears the variance. Per order, the bank
 *     row's settled = its allocated actual fee; the merchant row's settled =
 *     its estimate + (estimated bank − actual bank); platform/other settled =
 *     their estimate (the platform cut is fixed). So Σ(settled) == collected,
 *     exactly as Σ(estimate) did — value only moves between bank and merchant.
 *
 * Only orders with a card portion (an estimated bank row > 0) are targeted; a
 * pure-cash sale has no bank cut and its estimate is already final. Orders
 * already settled or already claimed into a payout are skipped. Reversible
 * while none of the settled orders have been paid out.
 */
final readonly class SettleCommissionAction
{
    public function __construct(private WriteAuditLogAction $writeAuditLog) {}

    /**
     * Summarise the unsettled card sales a settlement would target — so the
     * admin sees what they are about to reconcile and the current estimate.
     *
     * @return array{orders_count: int, card_gross: string, estimated_bank: string, platform_total: string, merchant_net_estimated: string}
     */
    public function preview(int $companyId, CarbonInterface $from, CarbonInterface $to, ?int $branchId): array
    {
        $orderIds = $this->targetOrderIds($companyId, $from, $to, $branchId, false);

        if ($orderIds === []) {
            return [
                'orders_count' => 0,
                'card_gross' => '0.000',
                'estimated_bank' => '0.000',
                'platform_total' => '0.000',
                'merchant_net_estimated' => '0.000',
            ];
        }

        $cardBaisas = $this->cardBaisasByOrder($orderIds);
        $rows = SaleCommission::query()->whereIn('order_id', $orderIds)->get();

        $estBank = 0;
        $platform = 0;
        $merchant = 0;
        foreach ($rows as $row) {
            $baisas = Money::toBaisas($row->commission_amount);
            match ($row->party_type) {
                'bank' => $estBank += $baisas,
                'platform' => $platform += $baisas,
                'merchant' => $merchant += $baisas,
                default => null,
            };
        }

        return [
            'orders_count' => count($orderIds),
            'card_gross' => Money::toOmr((int) array_sum($cardBaisas)),
            'estimated_bank' => Money::toOmr($estBank),
            'platform_total' => Money::toOmr($platform),
            'merchant_net_estimated' => Money::toOmr($merchant),
        ];
    }

    /**
     * Apply a settlement: allocate the actual bank fee, write the settled
     * amounts onto every targeted order's rows, and record the audit header.
     */
    public function settle(
        int $companyId,
        CarbonInterface $from,
        CarbonInterface $to,
        ?int $branchId,
        int $actualBankBaisas,
        string $source,
        ?int $bankId,
        ?string $statementDate,
        ?string $note,
        ?User $actor,
    ): CommissionSettlement {
        if ($actualBankBaisas < 0) {
            throw new RuntimeException('Actual bank fee cannot be negative.');
        }

        return DB::transaction(function () use ($companyId, $from, $to, $branchId, $actualBankBaisas, $source, $bankId, $statementDate, $note, $actor): CommissionSettlement {
            $orderIds = $this->targetOrderIds($companyId, $from, $to, $branchId, true);
            if ($orderIds === []) {
                throw new RuntimeException('No unsettled card sales for this merchant in the selected period.');
            }

            // Lock the whole breakdown of each target order.
            $rows = SaleCommission::query()->whereIn('order_id', $orderIds)->lockForUpdate()->get();
            $rowsByOrder = $rows->groupBy('order_id');

            $cardBaisas = $this->cardBaisasByOrder($orderIds);
            $totalCard = (int) array_sum($cardBaisas);

            if ($actualBankBaisas > $totalCard) {
                throw new RuntimeException('Actual bank fee cannot exceed the card sales total.');
            }

            $alloc = $this->allocate($actualBankBaisas, $cardBaisas, $totalCard);

            $estBankTotal = 0;
            $platformTotal = 0;
            $merchantNetTotal = 0;
            $settledByRowId = [];

            foreach ($orderIds as $orderId) {
                $orderRows = $rowsByOrder->get($orderId, collect());
                $bankEstByRow = [];
                $bankEstTotal = 0;
                $merchantEst = 0;
                foreach ($orderRows as $row) {
                    $baisas = Money::toBaisas($row->commission_amount);
                    if ($row->party_type === 'bank') {
                        $bankEstByRow[$row->id] = $baisas;
                        $bankEstTotal += $baisas;
                    } elseif ($row->party_type === 'merchant') {
                        $merchantEst = $baisas;
                    } elseif ($row->party_type === 'platform') {
                        $platformTotal += $baisas;
                    }
                }

                $bankActual = $alloc[$orderId] ?? 0;
                // Spread the order's actual bank fee across its bank row(s) by
                // their estimate weight (normally one row → it takes it all).
                $bankActualByRow = $this->allocate($bankActual, $bankEstByRow, $bankEstTotal);
                // Pass-through: the merchant absorbs the bank variance.
                $merchantSettled = $merchantEst + ($bankEstTotal - $bankActual);
                // Backstop: the actual fee allocated to an order can never
                // exceed its merchant share (an acquirer fee approaching ~100%
                // of card volume is garbage input — the global `> card total`
                // guard alone permits the == boundary). Reject rather than
                // write a nonsensical negative payable.
                if ($merchantSettled < 0) {
                    throw new RuntimeException('The actual bank fee is too high — it would make the merchant net negative. Check the entered amount.');
                }
                $estBankTotal += $bankEstTotal;
                $merchantNetTotal += $merchantSettled;

                foreach ($orderRows as $row) {
                    $settledByRowId[$row->id] = match ($row->party_type) {
                        'bank' => $bankActualByRow[$row->id] ?? 0,
                        'merchant' => $merchantSettled,
                        default => Money::toBaisas($row->commission_amount),
                    };
                }
            }

            $settlement = CommissionSettlement::create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'source' => $source,
                'bank_id' => $bankId,
                'statement_date' => $statementDate,
                'period_from' => $from,
                'period_to' => $to,
                'card_gross' => Money::toOmr($totalCard),
                'estimated_bank' => Money::toOmr($estBankTotal),
                'actual_bank' => Money::toOmr($actualBankBaisas),
                'platform_total' => Money::toOmr($platformTotal),
                'merchant_net' => Money::toOmr($merchantNetTotal),
                'variance' => Money::toOmr($actualBankBaisas - $estBankTotal),
                'orders_count' => count($orderIds),
                'status' => CommissionSettlement::STATUS_APPLIED,
                'note' => $note,
                'created_by_user_id' => $actor?->getKey(),
            ]);

            $now = now();
            foreach ($rows as $row) {
                $row->forceFill([
                    'settled_amount' => Money::toOmr($settledByRowId[$row->id] ?? Money::toBaisas($row->commission_amount)),
                    'is_settled' => true,
                    'settled_at' => $now,
                    'settlement_id' => $settlement->id,
                ])->save();
            }

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'commission.settled',
                actorUserId: $actor?->id,
                companyId: $companyId,
                branchId: $branchId,
                auditableType: CommissionSettlement::class,
                auditableId: (int) $settlement->id,
                newValues: [
                    'orders_count' => count($orderIds),
                    'card_gross' => Money::toOmr($totalCard),
                    'estimated_bank' => Money::toOmr($estBankTotal),
                    'actual_bank' => Money::toOmr($actualBankBaisas),
                    'merchant_net' => Money::toOmr($merchantNetTotal),
                    'variance' => Money::toOmr($actualBankBaisas - $estBankTotal),
                ],
            ));

            return $settlement->fresh();
        });
    }

    /**
     * Undo a settlement: clear the settled amounts so the orders fall back to
     * their estimate and can be re-settled. Blocked once any settled order has
     * been claimed into a payout (the payout must be cancelled first).
     */
    public function reverse(CommissionSettlement $settlement, ?User $actor): CommissionSettlement
    {
        if ($settlement->status !== CommissionSettlement::STATUS_APPLIED) {
            throw new RuntimeException('Only an applied settlement can be reversed.');
        }

        return DB::transaction(function () use ($settlement, $actor): CommissionSettlement {
            $rows = SaleCommission::query()
                ->where('settlement_id', $settlement->id)
                ->lockForUpdate()
                ->get();

            if ($rows->first(static fn (SaleCommission $row): bool => $row->payout_id !== null) !== null) {
                throw new RuntimeException('These settled sales have already been paid out; cancel the payout before reversing.');
            }

            foreach ($rows as $row) {
                $row->forceFill([
                    'settled_amount' => null,
                    'is_settled' => false,
                    'settled_at' => null,
                    'settlement_id' => null,
                ])->save();
            }

            $settlement->forceFill([
                'status' => CommissionSettlement::STATUS_REVERSED,
                'reversed_at' => now(),
                'reversed_by_user_id' => $actor?->getKey(),
            ])->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'commission.settlement_reversed',
                actorUserId: $actor?->id,
                companyId: (int) $settlement->company_id,
                branchId: $settlement->branch_id !== null ? (int) $settlement->branch_id : null,
                auditableType: CommissionSettlement::class,
                auditableId: (int) $settlement->id,
                newValues: ['orders_count' => $rows->pluck('order_id')->unique()->count()],
            ));

            return $settlement->fresh();
        });
    }

    /**
     * The order ids of the merchant's unsettled CARD sales in the window
     * (those with an estimated bank row > 0, not yet settled, not yet paid out).
     *
     * @return list<int>
     */
    private function targetOrderIds(int $companyId, CarbonInterface $from, CarbonInterface $to, ?int $branchId, bool $lock): array
    {
        $query = DB::table('pos_sale_commissions')
            ->where('company_id', $companyId)
            ->where('party_type', 'bank')
            ->where('is_settled', false)
            ->whereNull('payout_id')
            ->where('commission_amount', '>', 0)
            ->whereBetween('occurred_at', [$from, $to]);

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->orderBy('order_id')
            ->pluck('order_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Card-paid baisas per order (sum of its non-failed 'card' tenders).
     * Summed in integer baisas to avoid decimal-string SUM drift.
     *
     * @param  list<int>  $orderIds
     * @return array<int, int>
     */
    private function cardBaisasByOrder(array $orderIds): array
    {
        $payments = DB::table('pos_payments')
            ->whereIn('order_id', $orderIds)
            ->where('method', 'card')
            ->where('status', '!=', 'failed')
            ->get(['order_id', 'amount']);

        $card = [];
        foreach ($payments as $payment) {
            $orderId = (int) $payment->order_id;
            $card[$orderId] = ($card[$orderId] ?? 0) + Money::toBaisas($payment->amount);
        }
        foreach ($orderIds as $orderId) {
            $card[$orderId] ??= 0;
        }

        return $card;
    }

    /**
     * Largest-remainder split of $total baisas across orders weighted by card
     * volume. Σ == $total exactly.
     *
     * @param  array<int, int>  $weights  order_id => card baisas
     * @return array<int, int>  order_id => allocated baisas
     */
    private function allocate(int $total, array $weights, int $weightSum): array
    {
        $alloc = [];
        if ($total === 0) {
            foreach ($weights as $orderId => $weight) {
                $alloc[$orderId] = 0;
            }

            return $alloc;
        }

        if ($weightSum <= 0) {
            // Degenerate (no card volume to weight by) — split evenly.
            $orderIds = array_keys($weights);
            $count = count($orderIds);
            $base = intdiv($total, $count);
            $remainder = $total - $base * $count;
            foreach ($orderIds as $index => $orderId) {
                $alloc[$orderId] = $base + ($index < $remainder ? 1 : 0);
            }

            return $alloc;
        }

        $remainders = [];
        $sumFloor = 0;
        foreach ($weights as $orderId => $weight) {
            $exact = $total * $weight / $weightSum;
            $floor = (int) floor($exact);
            $alloc[$orderId] = $floor;
            $sumFloor += $floor;
            $remainders[$orderId] = $exact - $floor;
        }

        $leftover = $total - $sumFloor;
        arsort($remainders);
        foreach (array_keys($remainders) as $orderId) {
            if ($leftover <= 0) {
                break;
            }
            $alloc[$orderId] += 1;
            $leftover--;
        }

        return $alloc;
    }
}
