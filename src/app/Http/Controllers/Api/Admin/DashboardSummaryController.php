<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Enums\CompanyStatus;
use App\Enums\DeviceStatus;
use App\Enums\PlatformPermission;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use App\Models\Payment;
use App\Models\RoundupDonation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Single-shot endpoint that powers the Admin Dashboard landing page
 * (blueprint §4.8 — Slim Sprint 2 scope).
 *
 *   GET /admin/api/v1/dashboard/summary
 *
 * Returns every KPI the dashboard renders in one payload so the
 * landing page makes a single HTTP request instead of half a dozen.
 * Keeps the SPA snappy + makes the loading state trivial (one
 * spinner instead of N).
 *
 * What's IN the response (data we actually have):
 *   - company counts (total + breakdown by CompanyStatus)
 *   - branch count
 *   - device counts (total + breakdown by DeviceStatus + unassigned
 *     + heartbeat-derived fleet health: online / offline_assigned /
 *     low_battery — pos_api's /device/heartbeat writes last_seen_at
 *     and last_battery)
 *   - recent_merchants — last 5 onboarded companies for the table
 *   - recent_activity  — last 20 pos_audit_logs rows for the feed
 *
 * Present ONLY for admins holding reports.view (money + recon data
 * must not leak through the otherwise-ungated landing payload —
 * mirrors the dashboard's second permission-gated sales fetch):
 *   - roundup_today — successful pos_roundup_donations today
 *   - reconciliation_pending — pos_payments awaiting bank recon
 *
 * What's NOT here (still deferred):
 *   - Device out-of-fence alerts — geofence is enforced device-side;
 *     no server-side fence comparison exists yet.
 *
 * Each section is computed via a small focused query (no SELECT *,
 * no eager loads) so the payload + DB time stay tight even when the
 * platform grows. The status breakdowns use a single GROUP BY each
 * rather than N separate COUNT(*) queries.
 *
 * Permission: no specific permission required — every authenticated
 * admin can see the landing page. The sidebar already filters routes
 * by permission, and individual link-outs (e.g. into Merchants /
 * Devices / Audit Log) re-check at the destination. Only the two
 * money/recon keys above are conditionally included.
 */
class DashboardSummaryController extends Controller
{
    /**
     * A device counts as "online" when its last heartbeat is within
     * this many minutes. The Flutter app heartbeats far more often;
     * 5 minutes tolerates a couple of missed beats without flapping.
     */
    private const ONLINE_WINDOW_MINUTES = 5;

    /** Battery percentage below which a device is flagged. */
    private const LOW_BATTERY_THRESHOLD = 20;

    public function __invoke(Request $request): JsonResponse
    {
        $data = [
            'companies' => $this->companyStats(),
            'branches' => $this->branchStats(),
            'devices' => $this->deviceStats(),
            'recent_merchants' => $this->recentMerchants(),
            'recent_activity' => $this->recentActivity(),
        ];

        // Money + reconciliation tiles only for reports.view holders —
        // the base payload stays ungated for every authed admin.
        if ($request->user()?->can(PlatformPermission::ReportsView->value) ?? false) {
            $data['roundup_today'] = $this->roundupToday();
            $data['reconciliation_pending'] = $this->reconciliationPending();
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Total company count + per-status breakdown. The per-status map
     * always includes every CompanyStatus value (zeroed when no rows
     * match) so the frontend can render every chip without
     * conditional logic for the "no merchants yet" case.
     *
     * @return array<string, mixed>
     */
    private function companyStats(): array
    {
        // Single GROUP BY query → keyed dict. Cast to plain ints
        // because PostgreSQL returns counts as strings under PDO.
        $rows = Company::query()
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn ($v): int => (int) $v);

        // Hydrate the by_status map with all enum values, defaulting
        // missing ones to 0 — keeps the frontend's shape stable.
        $byStatus = [];
        foreach (CompanyStatus::cases() as $case) {
            $byStatus[$case->value] = $rows[$case->value] ?? 0;
        }

        return [
            'total' => array_sum($byStatus),
            'by_status' => $byStatus,
        ];
    }

    /**
     * Branch count. Currently just a single total — the blueprint
     * mentions a per-status pie too but every branch we have today
     * is 'active', so a breakdown adds no signal until BranchStatus
     * transitions become a real workflow.
     *
     * @return array<string, mixed>
     */
    private function branchStats(): array
    {
        return [
            'total' => Branch::query()->count(),
        ];
    }

    /**
     * Device counts: total, per-status breakdown, plus an explicit
     * `unassigned` count for the dashboard "needs placement" tile.
     * A device is unassigned iff branch_id IS NULL — same predicate
     * the Devices list page uses for its filter.
     *
     * @return array<string, mixed>
     */
    private function deviceStats(): array
    {
        $rows = Device::query()
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn ($v): int => (int) $v);

        $byStatus = [];
        foreach (DeviceStatus::cases() as $case) {
            $byStatus[$case->value] = $rows[$case->value] ?? 0;
        }

        // `unassigned` is computed separately because a device with
        // status=Registered can be (intentionally) sitting on a
        // shelf with no branch — both conditions correlate but are
        // not identical. The dashboard tile needs the literal
        // "branch_id is null" count.
        $unassigned = Device::query()
            ->whereNull('branch_id')
            ->count();

        // ---- Fleet health (blueprint §4.8 device alerts) ----
        // Heartbeat-derived counts. "Online" = last_seen_at within
        // the window; NULL last_seen_at means the device has never
        // phoned home and is treated as offline. Offline is only
        // alarming for devices that are actually placed at a branch
        // — a shelf device being silent is expected.
        $onlineSince = now()->subMinutes(self::ONLINE_WINDOW_MINUTES);

        $online = Device::query()
            ->where('last_seen_at', '>=', $onlineSince)
            ->count();

        $offlineAssigned = Device::query()
            ->whereNotNull('branch_id')
            ->where(function ($q) use ($onlineSince): void {
                $q->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', $onlineSince);
            })
            ->count();

        $lowBattery = Device::query()
            ->whereNotNull('last_battery')
            ->where('last_battery', '<', self::LOW_BATTERY_THRESHOLD)
            ->count();

        return [
            'total' => array_sum($byStatus),
            'by_status' => $byStatus,
            'unassigned' => $unassigned,
            'online' => $online,
            'offline_assigned' => $offlineAssigned,
            'low_battery' => $lowBattery,
        ];
    }

    /**
     * Today's successful charity round-up donations (blueprint §4.8
     * "Today's round-up"). Success only — that's money actually
     * collected; pending/failed rows live in the full Round-Up
     * report one click away. Same occurred_at + status semantics as
     * {@see \App\Actions\Admin\Reports\AdminRoundUpReportAction}.
     *
     * @return array{total: string, count: int}
     */
    private function roundupToday(): array
    {
        $row = RoundupDonation::query()
            ->where('status', 'success')
            ->whereBetween('occurred_at', [now()->startOfDay(), now()->endOfDay()])
            ->selectRaw('COALESCE(SUM(amount), 0) AS total, COUNT(*) AS cnt')
            ->first();

        return [
            'total' => number_format((float) ($row?->total ?? 0), 3, '.', ''),
            'count' => (int) ($row?->cnt ?? 0),
        ];
    }

    /**
     * Soft POS reconciliation queue (blueprint §4.8): card payments
     * recorded offline that still need to be matched against a bank
     * settlement file. Uses the dedicated pending_reconciliation
     * boolean + its index (the payments migration calls this query
     * out as the admin portal's hot path). Cleared by the Bank
     * Reconciliation page's commit action.
     *
     * @return array{count: int, amount: string}
     */
    private function reconciliationPending(): array
    {
        $row = Payment::query()
            ->where('pending_reconciliation', true)
            ->selectRaw('COALESCE(SUM(amount), 0) AS total, COUNT(*) AS cnt')
            ->first();

        return [
            'count' => (int) ($row?->cnt ?? 0),
            'amount' => number_format((float) ($row?->total ?? 0), 3, '.', ''),
        ];
    }

    /**
     * Most-recently-onboarded merchants for the dashboard's "Recent
     * Merchant Onboarding" table. Limited to 5 — the existing
     * /merchants list page is one click away for the full view.
     *
     * Returns a flat shape (no full CompanyResource) because the
     * table only renders name + contact + branches + devices +
     * status. Pulling the smaller projection saves payload bytes on
     * a page that's already doing a lot.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentMerchants(): array
    {
        return Company::query()
            ->withCount(['branches', 'devices'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(static fn (Company $company): array => [
                'id' => $company->id,
                'uuid' => $company->uuid,
                'name' => $company->name,
                'name_ar' => $company->name_ar,
                'contact_name' => $company->contact_name,
                'status' => $company->status?->value,
                'branches_count' => (int) $company->branches_count,
                'devices_count' => (int) $company->devices_count,
                'created_at' => $company->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Last 20 audit-log rows for the dashboard's "Live activity"
     * feed. Reuses {@see AuditLogResource} so the dashboard cell and
     * the full Audit Log viewer page render the same shape — no
     * shape drift between them as new fields get added.
     *
     * Eager-loads the same small relations the Audit Log page
     * loads (actor + company + branch) so the feed can show
     * who-did-what-to-whom without N+1.
     *
     * @return array<int, mixed>
     */
    private function recentActivity(): array
    {
        $rows = AuditLog::query()
            ->with(['actor:id,name,email', 'company:id,uuid,name,name_ar', 'branch:id,uuid,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return AuditLogResource::collection($rows)
            ->resolve(); // Convert to plain array for the JSON envelope.
    }
}
