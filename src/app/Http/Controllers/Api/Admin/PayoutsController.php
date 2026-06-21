<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\Payouts\CancelPayoutAction;
use App\Actions\Admin\Payouts\CreatePayoutAction;
use App\Actions\Admin\Payouts\MarkPayoutPaidAction;
use App\Actions\Admin\Payouts\PayoutBranchLinesAction;
use App\Enums\PlatformPermission;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\PayoutResource;
use App\Models\Company;
use App\Models\Payout;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * v2 #17 (Phase B) — merchant payouts (the platform settlement workflow).
 *
 *   GET  /admin/api/v1/payouts[?company_uuid=&status=]   → list (reports.view)
 *   POST /admin/api/v1/payouts                           → create pending (settings.manage)
 *   POST /admin/api/v1/payouts/{payout:uuid}/mark-paid   → mark paid (settings.manage)
 *   POST /admin/api/v1/payouts/{payout:uuid}/cancel      → cancel + release (settings.manage)
 *
 * Read on reports.view; the money-moving actions on settings.manage — the same
 * gate the bank-reconciliation commit uses.
 */
class PayoutsController extends Controller
{
    public function __construct(
        private readonly CreatePayoutAction $create,
        private readonly MarkPayoutPaidAction $markPaid,
        private readonly CancelPayoutAction $cancel,
        private readonly PayoutBranchLinesAction $branchLines,
    ) {}

    /** The payout's per-branch breakdown (the statement detail). reports.view. */
    public function lines(Request $request, Payout $payout): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(PlatformPermission::ReportsView->value), 403);

        return response()->json(['data' => $this->branchLines->handle($payout)]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can(PlatformPermission::ReportsView->value), 403);

        $query = Payout::query()
            ->leftJoin('pos_companies', 'pos_companies.id', '=', 'pos_payouts.company_id')
            ->leftJoin('pos_branches', 'pos_branches.id', '=', 'pos_payouts.branch_id')
            ->select('pos_payouts.*', 'pos_companies.name as company_name', 'pos_companies.uuid as company_uuid', 'pos_branches.name as branch_name');

        if ($request->filled('company_uuid')) {
            $companyId = Company::query()->where('uuid', $request->string('company_uuid')->value())->value('id') ?? 0;
            $query->where('pos_payouts.company_id', $companyId);
        }
        if ($request->filled('status')) {
            $query->where('pos_payouts.status', (string) $request->query('status'));
        }

        return PayoutResource::collection(
            $query->orderByDesc('pos_payouts.created_at')->paginate(50),
        );
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(PlatformPermission::SettingsManage->value), 403);

        $validated = $request->validate([
            'company_uuid' => ['required', 'string', 'uuid'],
            'branch_uuid' => ['nullable', 'string', 'uuid'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        $companyId = Company::query()->where('uuid', $validated['company_uuid'])->value('id');
        if ($companyId === null) {
            return response()->json(['message' => 'Merchant not found.'], 422);
        }

        $branchId = null;
        if (! empty($validated['branch_uuid'])) {
            $branchId = \App\Models\Branch::query()->where('uuid', $validated['branch_uuid'])->where('company_id', $companyId)->value('id');
            if ($branchId === null) {
                return response()->json(['message' => 'Branch not found for this merchant.'], 422);
            }
        }

        $from = CarbonImmutable::parse($validated['from'])->startOfDay();
        $to = CarbonImmutable::parse($validated['to'])->endOfDay();

        try {
            $payout = $this->create->handle((int) $companyId, $from, $to, $request->user()?->getKey(), $branchId !== null ? (int) $branchId : null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => (new PayoutResource($payout))->resolve($request)], 201);
    }

    public function markPaid(Request $request, Payout $payout): PayoutResource | JsonResponse
    {
        abort_unless((bool) $request->user()?->can(PlatformPermission::SettingsManage->value), 403);

        $validated = $request->validate([
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $updated = $this->markPaid->handle($payout, $request->user()?->getKey(), $validated['reference'] ?? null, $validated['note'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return PayoutResource::make($updated);
    }

    public function cancel(Request $request, Payout $payout): PayoutResource | JsonResponse
    {
        abort_unless((bool) $request->user()?->can(PlatformPermission::SettingsManage->value), 403);

        try {
            $updated = $this->cancel->handle($payout);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return PayoutResource::make($updated);
    }
}
