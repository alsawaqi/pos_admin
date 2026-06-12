<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Enums\OrderStatus;
use App\Enums\PlatformPermission;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\OrderResource;
use App\Models\Company;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Throwable;

/**
 * Platform-wide Sales / Orders viewer (blueprint sales visibility).
 *
 *   GET /admin/api/v1/orders -> paginated, date-filterable list of EVERY
 *                               merchant's orders. The admin is not
 *                               tenant-scoped, so Order::query() spans all
 *                               companies.
 *
 * reports.view gated. Filters (all optional, AND-combined): from / to on
 * opened_at, company_uuid, status. A `totals` block sums grand_total over
 * the whole filtered set (not just the page). Filter parsing mirrors the
 * AuditLogsController so behaviour is consistent across admin viewers.
 */
class OrdersController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->can(PlatformPermission::ReportsView->value) ?? false, 403);

        // P-G7 — pending-verification deliveries are visible in the list
        // but are NOT revenue until the merchant confirms the provider's
        // statement, so the totals banner excludes them — unless the user
        // explicitly filtered for that status (then the banner is the
        // outstanding total, and excluding would show 0.000 over rows of
        // visible money).
        $totalsQuery = $this->buildFilteredQuery($request);
        if ((string) $request->input('status') !== OrderStatus::PendingVerification->value) {
            $totalsQuery->where('status', '!=', OrderStatus::PendingVerification->value);
        }
        $grandTotal = (float) $totalsQuery->sum('grand_total');

        $page = $this->buildFilteredQuery($request)
            ->with(['company:id,uuid,name,name_ar', 'branch:id,uuid,name'])
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 25), 100));

        return OrderResource::collection($page)->additional([
            'totals' => [
                'count' => $page->total(),
                'grand_total' => number_format($grandTotal, 3, '.', ''),
            ],
        ]);
    }

    private function buildFilteredQuery(Request $request): Builder
    {
        /** @var Builder<Order> $query */
        $query = Order::query();

        if ($request->filled('company_uuid')) {
            $companyId = Company::query()
                ->where('uuid', $request->string('company_uuid')->value())
                ->value('id');
            // Unknown uuid -> no rows (not "all rows").
            $query->where('company_id', $companyId ?? 0);
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('from')) {
            $from = $this->parseDateBoundary((string) $request->input('from'), startOfDay: true);
            if ($from !== null) {
                $query->where('opened_at', '>=', $from);
            }
        }

        if ($request->filled('to')) {
            $to = $this->parseDateBoundary((string) $request->input('to'), startOfDay: false);
            if ($to !== null) {
                $query->where('opened_at', '<=', $to);
            }
        }

        return $query;
    }

    private function parseDateBoundary(string $raw, bool $startOfDay): ?CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::parse(trim($raw));
        } catch (Throwable) {
            return null;
        }

        $hasTimeComponent = (bool) preg_match('/[Tt]|\s\d{2}:/', $raw);
        if (! $hasTimeComponent) {
            return $startOfDay ? $parsed->startOfDay() : $parsed->endOfDay();
        }

        return $parsed;
    }
}
