<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\Reports\AdminRoundUpReportAction;
use App\Enums\PlatformPermission;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Platform round-up donation report (v2 #18).
 *
 *   GET /admin/api/v1/roundup-report?from=&to=&company_uuid=
 *
 * Charity raised across merchants + per-merchant breakdown. No company_uuid →
 * all merchants; with one → that merchant only. reports.view gated; date parsing
 * mirrors the sales/settlement reports; defaults to the trailing 30 days.
 */
class RoundUpReportController extends Controller
{
    public function __construct(
        private readonly AdminRoundUpReportAction $action,
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
