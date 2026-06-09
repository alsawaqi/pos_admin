<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\Reports\AdminSalesReportAction;
use App\Enums\PlatformPermission;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Platform sales report (v2 #16 + #19).
 *
 *   GET /admin/api/v1/sales-report?from=&to=&company_uuid=
 *
 * No company_uuid → platform-wide (admin dashboard graphs). With a
 * company_uuid → that merchant only (the per-merchant Sales tab).
 * reports.view gated. Date parsing mirrors OrdersController: bare
 * dates snap to start/end of day. Defaults to the trailing 30 days.
 */
class SalesReportController extends Controller
{
    public function __construct(
        private readonly AdminSalesReportAction $action,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can(PlatformPermission::ReportsView->value) ?? false, 403);

        $from = $this->parseBoundary((string) $request->input('from', ''), startOfDay: true)
            ?? CarbonImmutable::now()->subDays(29)->startOfDay();
        $to = $this->parseBoundary((string) $request->input('to', ''), startOfDay: false)
            ?? CarbonImmutable::now()->endOfDay();

        $companyId = null;
        if ($request->filled('company_uuid')) {
            // Unknown uuid → company_id 0 (no rows), never "all merchants".
            $companyId = Company::query()
                ->where('uuid', $request->string('company_uuid')->value())
                ->value('id') ?? 0;
        }

        return response()->json(['data' => $this->action->handle($companyId, $from, $to)]);
    }

    private function parseBoundary(string $raw, bool $startOfDay): ?CarbonImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        try {
            $parsed = CarbonImmutable::parse($raw);
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
