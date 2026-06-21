<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\Reconciliation\SettleCommissionAction;
use App\Actions\Admin\Reconciliation\SettlementOrdersAction;
use App\Actions\Admin\Reconciliation\SettlementPendingAction;
use App\Enums\PlatformPermission;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\CommissionSettlementResource;
use App\Models\Branch;
use App\Models\CommissionSettlement;
use App\Models\Company;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Commission settlement — reconcile a merchant's card sales against the bank's
 * ACTUAL fee and finalise the exact merchant net (estimate → settled).
 *
 *   GET  /admin/api/v1/commission-settlements[?company_uuid=&status=] → list (reports.view)
 *   GET  /admin/api/v1/commission-settlements/preview                 → unsettled-card summary (settings.manage)
 *   POST /admin/api/v1/commission-settlements                         → apply a settlement (settings.manage)
 *   POST /admin/api/v1/commission-settlements/{settlement:uuid}/reverse → undo (settings.manage)
 *
 * Read on reports.view; the money-moving actions on settings.manage — the same
 * gate the bank-reconciliation + payout endpoints use.
 */
class CommissionSettlementController extends Controller
{
    public function __construct(
        private readonly SettleCommissionAction $settle,
        private readonly SettlementOrdersAction $orders,
        private readonly SettlementPendingAction $pending,
    ) {}

    /** The daily to-do: merchants → branches with card sales to settle. settings.manage. */
    public function pending(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(PlatformPermission::SettingsManage->value), 403);

        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        return response()->json([
            'data' => $this->pending->handle(
                CarbonImmutable::parse($validated['from'])->startOfDay(),
                CarbonImmutable::parse($validated['to'])->endOfDay(),
            ),
        ]);
    }

    /** The per-order reconciliation worklist for a branch + window. settings.manage. */
    public function orders(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(PlatformPermission::SettingsManage->value), 403);

        $validated = $request->validate([
            'company_uuid' => ['required', 'string', 'uuid'],
            'branch_uuid' => ['required', 'string', 'uuid'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
            'status' => ['nullable', Rule::in(['unsettled', 'settled', 'all'])],
        ]);

        [$companyId, $branchId, $error] = $this->resolveScope($validated);
        if ($error !== null || $branchId === null) {
            return response()->json(['message' => $error ?? 'Branch is required.'], 422);
        }

        return response()->json([
            'data' => $this->orders->handle(
                $companyId,
                $branchId,
                CarbonImmutable::parse($validated['from'])->startOfDay(),
                CarbonImmutable::parse($validated['to'])->endOfDay(),
                $validated['status'] ?? 'unsettled',
            ),
        ]);
    }

    /** Settle an explicit set of orders, each at its own actual bank fee. settings.manage. */
    public function settleOrders(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(PlatformPermission::SettingsManage->value), 403);

        $validated = $request->validate([
            'company_uuid' => ['required', 'string', 'uuid'],
            'branch_uuid' => ['nullable', 'string', 'uuid'],
            'orders' => ['required', 'array', 'min:1'],
            'orders.*.order_uuid' => ['required', 'string', 'uuid'],
            'orders.*.actual_bank' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        [$companyId, $branchId, $error] = $this->resolveScope($validated);
        if ($error !== null) {
            return response()->json(['message' => $error], 422);
        }

        // Resolve the order uuids to ids, scoped to the company (no cross-tenant).
        $uuids = array_column($validated['orders'], 'order_uuid');
        $idByUuid = DB::table('pos_orders')
            ->where('company_id', $companyId)
            ->whereIn('uuid', $uuids)
            ->pluck('id', 'uuid');

        $actualByOrder = [];
        foreach ($validated['orders'] as $line) {
            $orderId = $idByUuid[$line['order_uuid']] ?? null;
            if ($orderId === null) {
                return response()->json(['message' => 'One or more orders were not found for this merchant.'], 422);
            }
            $actualByOrder[(int) $orderId] = Money::toBaisas($line['actual_bank']);
        }

        try {
            $settlement = $this->settle->settleOrders($companyId, $actualByOrder, $branchId, CommissionSettlement::SOURCE_MANUAL, $validated['note'] ?? null, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => (new CommissionSettlementResource($settlement))->resolve($request)], 201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can(PlatformPermission::ReportsView->value), 403);

        $query = CommissionSettlement::query()
            ->leftJoin('pos_companies', 'pos_companies.id', '=', 'pos_commission_settlements.company_id')
            ->select('pos_commission_settlements.*', 'pos_companies.name as company_name', 'pos_companies.uuid as company_uuid');

        if ($request->filled('company_uuid')) {
            $companyId = Company::query()->where('uuid', $request->string('company_uuid')->value())->value('id') ?? 0;
            $query->where('pos_commission_settlements.company_id', $companyId);
        }
        if ($request->filled('status')) {
            $query->where('pos_commission_settlements.status', (string) $request->query('status'));
        }

        return CommissionSettlementResource::collection(
            $query->orderByDesc('pos_commission_settlements.created_at')->paginate(50),
        );
    }

    public function preview(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(PlatformPermission::SettingsManage->value), 403);

        $validated = $request->validate([
            'company_uuid' => ['required', 'string', 'uuid'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
            'branch_uuid' => ['nullable', 'string', 'uuid'],
        ]);

        [$companyId, $branchId, $error] = $this->resolveScope($validated);
        if ($error !== null) {
            return response()->json(['message' => $error], 422);
        }

        return response()->json([
            'data' => $this->settle->preview(
                $companyId,
                CarbonImmutable::parse($validated['from'])->startOfDay(),
                CarbonImmutable::parse($validated['to'])->endOfDay(),
                $branchId,
            ),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(PlatformPermission::SettingsManage->value), 403);

        $validated = $request->validate([
            'company_uuid' => ['required', 'string', 'uuid'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
            'branch_uuid' => ['nullable', 'string', 'uuid'],
            'actual_bank' => ['required', 'numeric', 'min:0'],
            'source' => ['nullable', Rule::in([CommissionSettlement::SOURCE_MANUAL, CommissionSettlement::SOURCE_BANK_FILE])],
            'statement_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        [$companyId, $branchId, $error] = $this->resolveScope($validated);
        if ($error !== null) {
            return response()->json(['message' => $error], 422);
        }

        try {
            $settlement = $this->settle->settle(
                $companyId,
                CarbonImmutable::parse($validated['from'])->startOfDay(),
                CarbonImmutable::parse($validated['to'])->endOfDay(),
                $branchId,
                Money::toBaisas($validated['actual_bank']),
                $validated['source'] ?? CommissionSettlement::SOURCE_MANUAL,
                null,
                $validated['statement_date'] ?? null,
                $validated['note'] ?? null,
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => (new CommissionSettlementResource($settlement))->resolve($request)], 201);
    }

    public function reverse(Request $request, CommissionSettlement $settlement): JsonResponse | CommissionSettlementResource
    {
        abort_unless((bool) $request->user()?->can(PlatformPermission::SettingsManage->value), 403);

        try {
            $updated = $this->settle->reverse($settlement, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return CommissionSettlementResource::make($updated);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: int, 1: ?int, 2: ?string}  [companyId, branchId, error]
     */
    private function resolveScope(array $validated): array
    {
        $companyId = Company::query()->where('uuid', $validated['company_uuid'])->value('id');
        if ($companyId === null) {
            return [0, null, 'Merchant not found.'];
        }

        $branchId = null;
        if (! empty($validated['branch_uuid'])) {
            $branchId = Branch::query()
                ->where('uuid', $validated['branch_uuid'])
                ->where('company_id', $companyId)
                ->value('id');
            if ($branchId === null) {
                return [0, null, 'Branch not found for this merchant.'];
            }
        }

        return [(int) $companyId, $branchId !== null ? (int) $branchId : null, null];
    }
}
