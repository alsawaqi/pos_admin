<?php

declare(strict_types=1);

namespace App\Actions\Admin\Reconciliation;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\CommissionSettlement;
use App\Models\SaleCommission;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
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

            $cardBaisas = $this->cardBaisasByOrder($orderIds);
            $totalCard = (int) array_sum($cardBaisas);
            if ($actualBankBaisas > $totalCard) {
                throw new RuntimeException('Actual bank fee cannot exceed the card sales total.');
            }

            // Allocate the entered total across the orders by card volume, then
            // apply the per-order fees through the shared write path.
            $actualByOrder = $this->allocate($actualBankBaisas, $cardBaisas, $totalCard);

            return $this->applyToOrders($companyId, $actualByOrder, null, $cardBaisas, $branchId, $from, $to, $source, $bankId, $statementDate, $note, $actor);
        });
    }

    /**
     * VERIFY an explicit set of orders, each at its OWN actual bank fee (the
     * per-order verification path — the admin confirms/edits each sale one by
     * one, or fills them via select-all). Card sales verify against the bank
     * statement (editable fee); CASH/BANK_POS sales verify at bank fee 0 (no
     * acquirer — the platform commission is still confirmable/editable). Every
     * order must be an unsettled commissioned sale of the company (+ branch) not
     * yet claimed into a payout or a commission invoice; if any is not, the
     * whole batch is rejected.
     *
     * @param  array<int, int>  $actualByOrder    order_id => actual bank fee (baisas)
     * @param  array<int, int>  $platformByOrder  order_id => actual platform commission (baisas); an order absent here keeps its estimate
     */
    public function settleOrders(int $companyId, array $actualByOrder, array $platformByOrder, ?int $branchId, string $source, ?string $note, ?User $actor): CommissionSettlement
    {
        if ($actualByOrder === []) {
            throw new RuntimeException('No orders selected to settle.');
        }
        foreach ($actualByOrder as $baisas) {
            if ($baisas < 0) {
                throw new RuntimeException('Actual bank fee cannot be negative.');
            }
        }
        foreach ($platformByOrder as $baisas) {
            if ($baisas < 0) {
                throw new RuntimeException('Platform commission cannot be negative.');
            }
        }

        return DB::transaction(function () use ($companyId, $actualByOrder, $platformByOrder, $branchId, $source, $note, $actor): CommissionSettlement {
            $orderIds = array_keys($actualByOrder);
            $eligible = $this->eligibleOrderIds($companyId, $orderIds, $branchId);
            if (count($eligible) !== count($orderIds)) {
                throw new RuntimeException('Some selected orders are not verifiable (already verified, paid out, invoiced, or not commissioned sales of this branch).');
            }

            $cardBaisas = $this->cardBaisasByOrder($orderIds);
            [$from, $to] = $this->orderWindow($orderIds);

            return $this->applyToOrders($companyId, $actualByOrder, $platformByOrder, $cardBaisas, $branchId, $from, $to, $source, null, null, $note, $actor);
        });
    }

    /**
     * Shared write path: given a per-order map of actual bank fees, write the
     * settled amounts on every targeted order's rows + the settlement header +
     * audit. PASS-THROUGH per order: bank settled = actual, merchant settled =
     * estimate + (estimated bank − actual), platform/other = estimate, so
     * Σ(settled) == collected. Caller runs inside a DB transaction and has
     * locked/validated the orders.
     *
     * @param  array<int, int>       $bankByOrder      order_id => actual bank fee (baisas)
     * @param  array<int, int>|null  $platformByOrder  order_id => actual platform commission (baisas); null/absent keeps the estimate
     * @param  array<int, int>       $cardByOrder      order_id => card volume (baisas)
     */
    private function applyToOrders(int $companyId, array $bankByOrder, ?array $platformByOrder, array $cardByOrder, ?int $branchId, ?CarbonInterface $from, ?CarbonInterface $to, string $source, ?int $bankId, ?string $statementDate, ?string $note, ?User $actor): CommissionSettlement
    {
        $orderIds = array_keys($bankByOrder);
        $rows = SaleCommission::query()->whereIn('order_id', $orderIds)->lockForUpdate()->get();
        $rowsByOrder = $rows->groupBy('order_id');

        $estBankTotal = 0;
        $platformTotal = 0;
        $merchantNetTotal = 0;
        $actualTotal = 0;
        $settledByRowId = [];

        foreach ($orderIds as $orderId) {
            $orderRows = $rowsByOrder->get($orderId, collect());
            $bankEstByRow = [];
            $bankEstTotal = 0;
            $platformEstByRow = [];
            $platformEstTotal = 0;
            $merchantEst = 0;
            foreach ($orderRows as $row) {
                $baisas = Money::toBaisas($row->commission_amount);
                if ($row->party_type === 'bank') {
                    $bankEstByRow[$row->id] = $baisas;
                    $bankEstTotal += $baisas;
                } elseif ($row->party_type === 'platform') {
                    $platformEstByRow[$row->id] = $baisas;
                    $platformEstTotal += $baisas;
                } elseif ($row->party_type === 'merchant') {
                    $merchantEst = $baisas;
                }
            }

            $bankActual = max(0, (int) ($bankByOrder[$orderId] ?? 0));
            // A3 — the platform commission is editable per sale too. Default to
            // the estimate when the caller doesn't override it (the batch settle
            // path, which only ever touches the bank fee).
            $platformActual = ($platformByOrder !== null && array_key_exists($orderId, $platformByOrder))
                ? max(0, (int) $platformByOrder[$orderId])
                : $platformEstTotal;

            // A merchant whose profile has no platform share (e.g. a bank-only
            // acquirer split) produces card sales with NO platform row. There is
            // then nothing to hold a positive platform commission — reject it so
            // the residual invariant can't be broken (and never divide-by-zero
            // in the empty-weights allocate below). A 0 override is a no-op.
            if ($platformEstByRow === [] && $platformActual > 0) {
                throw new RuntimeException('This sale has no platform commission to adjust — leave its commission at 0.');
            }

            // Same fail-closed rule for the bank side, keyed off CARD MONEY (not
            // row presence — a cash sale under a profile WITH a bank line carries
            // a zero-value bank row, which must not let a fee through): no card
            // money → no acquirer → the fee must be 0.
            if ($bankActual > 0 && (int) ($cardByOrder[$orderId] ?? 0) === 0) {
                throw new RuntimeException('This sale has no card money — the bank fee must be 0.');
            }
            // And a positive fee needs a bank line to land on (fail closed,
            // never divide-by-zero in the empty-weights allocate below).
            if ($bankEstByRow === [] && $bankActual > 0) {
                throw new RuntimeException('This sale has no bank commission line to hold a fee — the bank fee must be 0.');
            }

            // Spread each actual across its row(s) by estimate weight (normally
            // one bank row + one platform row → each takes its whole amount).
            $bankActualByRow = $this->allocate($bankActual, $bankEstByRow, $bankEstTotal);
            $platformActualByRow = $this->allocate($platformActual, $platformEstByRow, $platformEstTotal);

            // The merchant is the RESIDUAL — they absorb both the bank and the
            // platform variance, so Σ(settled) == collected exactly (unchanged
            // invariant; value only moves between bank/platform and the merchant).
            $merchantSettled = $merchantEst + ($bankEstTotal - $bankActual) + ($platformEstTotal - $platformActual);
            if ($merchantSettled < 0) {
                throw new RuntimeException('The entered bank fee + commission are too high — they would make the merchant net negative. Check the amounts.');
            }
            $estBankTotal += $bankEstTotal;
            $platformTotal += $platformActual;
            $merchantNetTotal += $merchantSettled;
            $actualTotal += $bankActual;

            foreach ($orderRows as $row) {
                $settledByRowId[$row->id] = match ($row->party_type) {
                    'bank' => $bankActualByRow[$row->id] ?? 0,
                    'platform' => $platformActualByRow[$row->id] ?? 0,
                    'merchant' => $merchantSettled,
                    default => Money::toBaisas($row->commission_amount),
                };
            }
        }

        $totalCard = (int) array_sum($cardByOrder);

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
            'actual_bank' => Money::toOmr($actualTotal),
            'platform_total' => Money::toOmr($platformTotal),
            'merchant_net' => Money::toOmr($merchantNetTotal),
            'variance' => Money::toOmr($actualTotal - $estBankTotal),
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
                'actual_bank' => Money::toOmr($actualTotal),
                'merchant_net' => Money::toOmr($merchantNetTotal),
                'variance' => Money::toOmr($actualTotal - $estBankTotal),
            ],
        ));

        return $settlement->fresh();
    }

    /**
     * Undo a settlement: clear the settled amounts so the orders fall back to
     * their estimate and can be re-settled. Blocked once any settled order has
     * been claimed into a payout (cancel it first) OR billed on a commission
     * invoice (void it first) — both documents snapshot the VERIFIED figures,
     * so un-verifying underneath them would corrupt an issued financial
     * document (statement lines would no longer sum to the billed header).
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
            if ($rows->first(static fn (SaleCommission $row): bool => $row->invoice_id !== null) !== null) {
                throw new RuntimeException('These verified sales have already been billed on a commission invoice; void the invoice before reversing.');
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
            ->where('commission_amount', '>', 0)
            ->whereBetween('occurred_at', [$from, $to])
            // Never re-settle an order already claimed into a payout. The claim
            // stamps payout_id on the MERCHANT row (not this bank row), so guard
            // on ANY claimed row of the order, not this row's own payout_id.
            ->whereNotExists(function ($sub): void {
                $sub->select(DB::raw(1))
                    ->from('pos_sale_commissions as claimed')
                    ->whereColumn('claimed.order_id', 'pos_sale_commissions.order_id')
                    ->whereNotNull('claimed.payout_id');
            });

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
     * Of a given set of order ids, the subset that is VERIFIABLE for this
     * company (+ branch): an unsettled COMMISSIONED sale — card OR cash/bank_pos,
     * anchored on the merchant residual row every commissioned order carries —
     * not already claimed into a payout or a commission invoice (both freeze the
     * order's figures). Locked for the settle transaction.
     *
     * @param  list<int>  $orderIds
     * @return list<int>
     */
    private function eligibleOrderIds(int $companyId, array $orderIds, ?int $branchId): array
    {
        $query = DB::table('pos_sale_commissions')
            ->where('company_id', $companyId)
            ->where('party_type', 'merchant')
            ->where('is_settled', false)
            ->whereIn('order_id', $orderIds)
            ->whereNotExists(function ($sub): void {
                $sub->select(DB::raw(1))
                    ->from('pos_sale_commissions as claimed')
                    ->whereColumn('claimed.order_id', 'pos_sale_commissions.order_id')
                    ->where(static function ($q): void {
                        $q->whereNotNull('claimed.payout_id')
                            ->orWhereNotNull('claimed.invoice_id');
                    });
            })
            ->lockForUpdate();

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        return $query->pluck('order_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The [min, max] occurred_at of a set of orders (informational period on the
     * settlement header). Falls back to [now, now] if none recorded.
     *
     * @param  list<int>  $orderIds
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function orderWindow(array $orderIds): array
    {
        $row = DB::table('pos_sale_commissions')
            ->whereIn('order_id', $orderIds)
            ->selectRaw('MIN(occurred_at) AS lo, MAX(occurred_at) AS hi')
            ->first();

        $lo = ($row && $row->lo !== null) ? CarbonImmutable::parse($row->lo) : CarbonImmutable::now();
        $hi = ($row && $row->hi !== null) ? CarbonImmutable::parse($row->hi) : CarbonImmutable::now();

        return [$lo, $hi];
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
            if ($count === 0) {
                // No rows to receive a non-zero amount ($total === 0 already
                // returned above). Fail closed rather than divide by zero or
                // silently drop money — callers must guard this case first.
                throw new RuntimeException('Cannot allocate a non-zero amount with no target rows.');
            }
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
