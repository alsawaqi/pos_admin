<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\Invoices\BatchMarkCommissionInvoicesPaidAction;
use App\Actions\Admin\Invoices\CommissionInvoiceBranchLinesAction;
use App\Actions\Admin\Invoices\CreateCommissionInvoiceAction;
use App\Actions\Admin\Invoices\MarkCommissionInvoicePaidAction;
use App\Actions\Admin\Invoices\PendingCommissionInvoiceAction;
use App\Actions\Admin\Invoices\VoidCommissionInvoiceAction;
use App\Enums\PlatformPermission;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\CommissionInvoiceResource;
use App\Models\Branch;
use App\Models\CommissionInvoice;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * Phase B — commission INVOICES (the merchant-owes-platform settlement workflow,
 * the reverse of payouts).
 *
 *   GET  /admin/api/v1/commission-invoices[?company_uuid=&status=]  → list (reports.view)
 *   GET  /admin/api/v1/commission-invoices/pending                  → to-bill drill (reports.view)
 *   GET  /admin/api/v1/commission-invoices/{invoice:uuid}/lines     → statement detail (reports.view)
 *   POST /admin/api/v1/commission-invoices                          → issue (settings.manage)
 *   POST /admin/api/v1/commission-invoices/batch-mark-paid          → mark paid ×N (settings.manage)
 *   POST /admin/api/v1/commission-invoices/{invoice:uuid}/mark-paid → mark paid (settings.manage)
 *   POST /admin/api/v1/commission-invoices/{invoice:uuid}/void      → void + release (settings.manage)
 *
 * Read on reports.view; money-moving actions on settings.manage — the same gate
 * the payout + bank-reconciliation flows use.
 */
class CommissionInvoicesController extends Controller
{
    public function __construct(
        private readonly CreateCommissionInvoiceAction $create,
        private readonly MarkCommissionInvoicePaidAction $markPaid,
        private readonly BatchMarkCommissionInvoicesPaidAction $batchMarkPaid,
        private readonly VoidCommissionInvoiceAction $void,
        private readonly CommissionInvoiceBranchLinesAction $branchLines,
        private readonly PendingCommissionInvoiceAction $pending,
    ) {}

    /** Merchants/branches with un-invoiced cash/bank_pos commission. reports.view. */
    public function pendingList(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(PlatformPermission::ReportsView->value), 403);

        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);
        $from = CarbonImmutable::parse($validated['from'])->startOfDay();
        $to = CarbonImmutable::parse($validated['to'])->endOfDay();

        return response()->json(['data' => $this->pending->handle($from, $to)]);
    }

    /** The invoice's per-branch breakdown (the statement detail). reports.view. */
    public function lines(Request $request, CommissionInvoice $invoice): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(PlatformPermission::ReportsView->value), 403);

        return response()->json(['data' => $this->branchLines->handle($invoice)]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless((bool) $request->user()?->can(PlatformPermission::ReportsView->value), 403);

        $query = CommissionInvoice::query()
            ->leftJoin('pos_companies', 'pos_companies.id', '=', 'pos_commission_invoices.company_id')
            ->leftJoin('pos_branches', 'pos_branches.id', '=', 'pos_commission_invoices.branch_id')
            ->select('pos_commission_invoices.*', 'pos_companies.name as company_name', 'pos_companies.uuid as company_uuid', 'pos_branches.name as branch_name');

        if ($request->filled('company_uuid')) {
            $companyId = Company::query()->where('uuid', $request->string('company_uuid')->value())->value('id') ?? 0;
            $query->where('pos_commission_invoices.company_id', $companyId);
        }
        if ($request->filled('status')) {
            $query->where('pos_commission_invoices.status', (string) $request->query('status'));
        }

        return CommissionInvoiceResource::collection(
            $query->orderByDesc('pos_commission_invoices.created_at')->paginate(50),
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
            $branchId = Branch::query()->where('uuid', $validated['branch_uuid'])->where('company_id', $companyId)->value('id');
            if ($branchId === null) {
                return response()->json(['message' => 'Branch not found for this merchant.'], 422);
            }
        }

        $from = CarbonImmutable::parse($validated['from'])->startOfDay();
        $to = CarbonImmutable::parse($validated['to'])->endOfDay();

        try {
            $invoice = $this->create->handle((int) $companyId, $from, $to, $request->user()?->getKey(), $branchId !== null ? (int) $branchId : null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => (new CommissionInvoiceResource($invoice))->resolve($request)], 201);
    }

    public function markPaid(Request $request, CommissionInvoice $invoice): CommissionInvoiceResource | JsonResponse
    {
        abort_unless((bool) $request->user()?->can(PlatformPermission::SettingsManage->value), 403);

        $validated = $request->validate([
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $updated = $this->markPaid->handle($invoice, $request->user()?->getKey(), $validated['reference'] ?? null, $validated['note'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return CommissionInvoiceResource::make($updated);
    }

    /** Mark several issued invoices paid at once (skips non-issued). settings.manage. */
    public function batchMarkPaid(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(PlatformPermission::SettingsManage->value), 403);

        $validated = $request->validate([
            'invoice_uuids' => ['required', 'array', 'min:1'],
            'invoice_uuids.*' => ['required', 'string', 'uuid'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $this->batchMarkPaid->handle(
            $validated['invoice_uuids'],
            $request->user()?->getKey(),
            $validated['reference'] ?? null,
            $validated['note'] ?? null,
        );

        return response()->json(['data' => $result]);
    }

    public function void(Request $request, CommissionInvoice $invoice): CommissionInvoiceResource | JsonResponse
    {
        abort_unless((bool) $request->user()?->can(PlatformPermission::SettingsManage->value), 403);

        try {
            $updated = $this->void->handle($invoice, $request->user()?->getKey());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return CommissionInvoiceResource::make($updated);
    }
}
