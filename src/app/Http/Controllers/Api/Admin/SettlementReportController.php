<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\Reports\AdminSettlementReportAction;
use App\Enums\PlatformPermission;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Platform settlement report (v2 #17).
 *
 *   GET /admin/api/v1/settlement-report?from=&to=&company_uuid=
 *
 * Per-merchant commission breakdown + platform totals over the window (the
 * money the platform settles to each merchant + its own revenue). No
 * company_uuid → all merchants; with one → that merchant only. reports.view
 * gated. Date parsing mirrors SalesReportController; defaults to trailing 30d.
 */
class SettlementReportController extends Controller
{
    public function __construct(
        private readonly AdminSettlementReportAction $action,
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
