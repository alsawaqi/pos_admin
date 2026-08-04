<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\AdBilling\AdInvoiceLinesAction;
use App\Actions\Admin\AdBilling\CreateAdInvoiceAction;
use App\Actions\Admin\AdBilling\MarkAdInvoicePaidAction;
use App\Actions\Admin\AdBilling\PendingAdBillingAction;
use App\Actions\Admin\AdBilling\SetAdRateCardAction;
use App\Actions\Admin\AdBilling\VoidAdInvoiceAction;
use App\Enums\PlatformPermission;
use App\Http\Controllers\Controller;
use App\Models\AdInvoice;
use App\Models\AdRateCard;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Phase 5 — advertiser billing (invoice-first: bills generated from
 * DELIVERED impressions; the owner marks payments received).
 *
 *   GET  /admin/api/v1/ad-billing/pending?from&to      → advertisers to bill (reports.view)
 *   GET  /admin/api/v1/ad-billing/invoices             → invoice list (reports.view)
 *   GET  /admin/api/v1/ad-billing/invoices/{uuid}/lines→ per-branch detail (reports.view)
 *   GET  /admin/api/v1/ad-billing/rate-cards?advertiser_id → active + history (reports.view)
 *   POST /admin/api/v1/ad-billing/rate-cards           → set active card (settings.manage)
 *   POST /admin/api/v1/ad-billing/invoices             → issue (settings.manage)
 *   POST /admin/api/v1/ad-billing/invoices/{uuid}/mark-paid | void (settings.manage)
 *
 * Same gate split as commission invoices: read on reports.view,
 * money-moving on settings.manage.
 */
class AdBillingController extends Controller
{
    public function __construct(
        private readonly SetAdRateCardAction $setRateCard,
        private readonly CreateAdInvoiceAction $create,
        private readonly MarkAdInvoicePaidAction $markPaid,
        private readonly VoidAdInvoiceAction $void,
        private readonly PendingAdBillingAction $pending,
        private readonly AdInvoiceLinesAction $lines,
    ) {}

    public function pendingList(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(PlatformPermission::ReportsView->value), 403);

        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        return response()->json(['data' => $this->pending->handle(
            CarbonImmutable::parse($validated['from']),
            CarbonImmutable::parse($validated['to']),
        )]);
    }

    public function invoices(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(PlatformPermission::ReportsView->value), 403);

        $query = AdInvoice::query()
            ->leftJoin('advertisers', 'advertisers.id', '=', 'pos_ad_invoices.advertiser_id')
            ->select('pos_ad_invoices.*', 'advertisers.name as advertiser_name', 'advertisers.brand_name as brand_name');

        if ($request->filled('advertiser_id')) {
            $query->where('pos_ad_invoices.advertiser_id', (int) $request->query('advertiser_id'));
        }
        if ($request->filled('status')) {
            $query->where('pos_ad_invoices.status', (string) $request->query('status'));
        }

        return response()->json(
            $query->orderByDesc('pos_ad_invoices.created_at')->paginate(50),
        );
    }

    public function lines(Request $request, AdInvoice $invoice): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(PlatformPermission::ReportsView->value), 403);

        return response()->json(['data' => $this->lines->handle($invoice)]);
    }

    public function rateCards(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(PlatformPermission::ReportsView->value), 403);

        $validated = $request->validate(['advertiser_id' => ['required', 'integer']]);

        return response()->json(['data' => AdRateCard::query()
            ->where('advertiser_id', (int) $validated['advertiser_id'])
            ->orderByDesc('id')
            ->limit(20)
            ->get()]);
    }

    public function storeRateCard(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(PlatformPermission::SettingsManage->value), 403);

        $validated = $request->validate([
            'advertiser_id' => ['required', 'integer'],
            'pricing_model' => ['required', 'string', 'in:'.implode(',', AdRateCard::MODELS)],
            'rate' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $card = $this->setRateCard->handle(
                (int) $validated['advertiser_id'],
                (string) $validated['pricing_model'],
                (string) $validated['rate'],
                $request->user()?->id,
                $validated['notes'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $card], 201);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(PlatformPermission::SettingsManage->value), 403);

        $validated = $request->validate([
            'advertiser_id' => ['required', 'integer'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $invoice = $this->create->handle(
                (int) $validated['advertiser_id'],
                CarbonImmutable::parse($validated['from']),
                CarbonImmutable::parse($validated['to']),
                $request->user()?->id,
                $validated['note'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $invoice], 201);
    }

    public function markPaid(Request $request, AdInvoice $invoice): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(PlatformPermission::SettingsManage->value), 403);

        try {
            return response()->json(['data' => $this->markPaid->handle($invoice, $request->user()?->id)]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function voidInvoice(Request $request, AdInvoice $invoice): JsonResponse
    {
        abort_unless((bool) $request->user()?->can(PlatformPermission::SettingsManage->value), 403);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        try {
            return response()->json(['data' => $this->void->handle($invoice, $request->user()?->id, $validated['reason'] ?? null)]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
