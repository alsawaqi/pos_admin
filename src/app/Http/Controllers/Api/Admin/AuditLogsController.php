<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Admin endpoints for the platform-wide audit log viewer
 * (blueprint §4.7 — final Phase 2 piece).
 *
 *   GET /admin/api/v1/audit-logs               → paginated, filterable index
 *   GET /admin/api/v1/audit-logs/export.csv    → streamed CSV of the same query
 *
 * Both routes share the exact same filter parser so the CSV always
 * matches what the user sees on screen — "Export" means "give me a
 * file of *these* rows", not "of all rows".
 *
 * Filters (all optional, all combine with AND):
 *   - actor_id        : pos_users.id (numeric)
 *   - event           : substring match on the event string
 *                       (`device.assigned`, `company.created`, …);
 *                       LIKE %term% so partial matches work
 *   - target_type     : short name from {@see AuditLogResource::TARGET_TYPE_MAP}
 *                       (`company`, `branch`, `device`, `user`)
 *   - company_uuid    : pos_companies.uuid; resolved server-side to id
 *   - branch_uuid     : pos_branches.uuid; resolved server-side to id
 *   - from            : ISO-8601 date / datetime, inclusive lower bound
 *   - to              : ISO-8601 date / datetime, inclusive upper bound
 *
 * Pagination:
 *   - per_page defaults to 25, capped at 100 (same as the other
 *     admin controllers). CSV ignores per_page and instead caps the
 *     total export at AUDIT_LOG_EXPORT_CAP rows — see {@see export()}.
 *
 * Permission: AuditLogsView (every platform role today, per the
 * seeder). Enforced via {@see \App\Policies\AuditLogPolicy}.
 */
class AuditLogsController extends Controller
{
    /**
     * Hard ceiling on the number of rows we stream into a CSV in a
     * single request. The log can grow unbounded (every audit-able
     * action writes a row), so we cap exports to keep memory + the
     * downstream Excel open-time predictable. If finance ever needs
     * a full dump we'd add a queued export job; until then 50 k rows
     * is enough to cover a year of pilot traffic in one click.
     */
    private const AUDIT_LOG_EXPORT_CAP = 50_000;

    /**
     * GET /admin/api/v1/audit-logs
     *
     * Paginated list. Eager-loads actor + company + branch in one
     * query so the table cells render without N+1 SELECTs (page of
     * 25 rows would otherwise issue up to 75 extra queries).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AuditLog::class);

        $query = $this->buildFilteredQuery($request)
            // Eager load only the relations the resource serialises —
            // the auditable morph itself is *not* loaded because we
            // expose target_type + target_id on the resource and let
            // the SPA link out (every targeted entity has its own
            // detail page that already handles the lookup).
            ->with(['actor:id,name,email', 'company:id,uuid,name,name_ar', 'branch:id,uuid,name']);

        // Newest first — the table opens to a "what just happened"
        // view by default; users filter from there.
        $page = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 25), 100));

        return AuditLogResource::collection($page);
    }

    /**
     * GET /admin/api/v1/audit-logs/export.csv
     *
     * Streams a CSV of the filtered audit log straight to the
     * client. Uses a {@see StreamedResponse} + chunked Eloquent query
     * so we never materialise more than AUDIT_LOG_EXPORT_CHUNK rows
     * in PHP memory at a time, which keeps the export safe even on
     * large date ranges.
     *
     * Columns: a flat, finance-friendly shape. The before/after JSON
     * blobs are intentionally omitted from the CSV — they don't fit
     * a spreadsheet cell cleanly and the diff drawer in the UI is
     * the right tool for inspecting them. If they're ever needed for
     * compliance, an `include_diff=1` flag would add `old_values_json`
     * + `new_values_json` columns; not built yet.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('export', AuditLog::class);

        $query = $this->buildFilteredQuery($request)
            ->with(['actor:id,name,email', 'company:id,uuid,name', 'branch:id,uuid,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            // Defence in depth — the chunkById loop below would also
            // respect this limit, but capping at the query level
            // avoids surprises if a future code path bypasses chunk.
            ->limit(self::AUDIT_LOG_EXPORT_CAP);

        // Build a timestamped filename so successive exports don't
        // overwrite each other in the user's Downloads folder. UTC
        // timestamp avoids ambiguity across team time zones.
        $filename = 'audit-log-'.now()->utc()->format('Ymd-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            // Disables proxy buffering on nginx / cloud edges so the
            // stream actually flushes incrementally instead of being
            // collected and sent in one big chunk at the end.
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ];

        return response()->stream(function () use ($query): void {
            $out = fopen('php://output', 'w');

            // Excel + LibreOffice both detect UTF-8 reliably when a
            // BOM is present; without it, Excel mis-renders Arabic
            // names in the actor/company columns as mojibake.
            fwrite($out, "\xEF\xBB\xBF");

            // Header row. Keep the column order stable — downstream
            // analytics workbooks reference columns by position.
            fputcsv($out, [
                'id',
                'occurred_at_utc',
                'event',
                'actor_id',
                'actor_name',
                'actor_email',
                'company_uuid',
                'company_name',
                'branch_uuid',
                'branch_name',
                'target_type',
                'target_id',
                'ip_address',
                'user_agent',
            ]);

            // chunkById streams in batches of 500, releasing each
            // batch from memory between iterations. The cursor is
            // safe even if new audit rows are written during the
            // export (it tracks `id` not `offset`).
            $query->chunkById(500, function ($rows) use ($out): void {
                /** @var \Illuminate\Support\Collection<int, AuditLog> $rows */
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row->id,
                        optional($row->created_at)->utc()->format('Y-m-d\TH:i:s\Z'),
                        $row->event,
                        $row->actor?->id,
                        $row->actor?->name,
                        $row->actor?->email,
                        $row->company?->uuid,
                        $row->company?->name,
                        $row->branch?->uuid,
                        $row->branch?->name,
                        // Reuse the short-name map from the resource
                        // so on-screen and CSV target_type values
                        // never drift apart.
                        $this->shortTargetType($row->auditable_type),
                        $row->auditable_id,
                        $row->ip_address,
                        // Truncate UA so a single rogue row doesn't
                        // blow a CSV cell past Excel's 32 k char
                        // ceiling. 512 chars is way more than any
                        // real-world UA string.
                        $row->user_agent !== null
                            ? mb_substr($row->user_agent, 0, 512)
                            : null,
                    ]);
                }
            });

            fclose($out);
        }, 200, $headers);
    }

    /**
     * Builds the base Eloquent query with every requested filter
     * applied. Extracted so {@see index()} and {@see export()} share
     * one source of truth — the CSV must always match the visible
     * table for the same filter combination.
     */
    private function buildFilteredQuery(Request $request): Builder
    {
        /** @var Builder<AuditLog> $query */
        $query = AuditLog::query();

        // ---- actor ------------------------------------------------
        // Numeric pos_users.id. The frontend renders the actor
        // picker from /admin/api/v1/audit-logs/actors (lightweight
        // distinct list — TBD if the dropdown grows past a few
        // hundred admins; today we send the raw id from the table
        // row click "filter by this actor").
        if ($request->filled('actor_id')) {
            $query->where('actor_user_id', $request->integer('actor_id'));
        }

        // ---- event ------------------------------------------------
        // Substring match (LIKE %term%) so a filter on `device.`
        // catches every device.* event. Trim + ignore empty to keep
        // an accidental whitespace-only input from matching every
        // row in the table.
        if ($request->filled('event')) {
            $term = trim((string) $request->input('event'));
            if ($term !== '') {
                $query->where('event', 'like', '%'.$term.'%');
            }
        }

        // ---- target type -----------------------------------------
        // Short name from the resource's map → FQCN for the WHERE.
        // Unknown short names produce no rows (instead of an error)
        // so a stale frontend dropdown can't crash the table.
        if ($request->filled('target_type')) {
            $short = (string) $request->input('target_type');
            $fqcn = AuditLogResource::TARGET_TYPE_MAP[$short] ?? null;
            if ($fqcn !== null) {
                $query->where('auditable_type', $fqcn);
            } else {
                // Force "no results" instead of returning everything
                // when the client sends a target_type we don't map.
                $query->whereRaw('1 = 0');
            }
        }

        // ---- company (by UUID) ----------------------------------
        // We resolve UUID → numeric id once instead of joining, so
        // the audit_logs table can use its existing
        // (company_id, event) index without extra query planning.
        if ($request->filled('company_uuid')) {
            $companyId = Company::query()
                ->where('uuid', $request->string('company_uuid')->value())
                ->value('id');

            if ($companyId !== null) {
                $query->where('company_id', $companyId);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // ---- branch (by UUID) -----------------------------------
        if ($request->filled('branch_uuid')) {
            $branchId = Branch::query()
                ->where('uuid', $request->string('branch_uuid')->value())
                ->value('id');

            if ($branchId !== null) {
                $query->where('branch_id', $branchId);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // ---- date range -----------------------------------------
        // Both bounds are inclusive. `from` snaps to the start of
        // the day if a bare date is supplied, `to` snaps to the
        // end. Parsing failures are swallowed silently (the filter
        // is just ignored) so a malformed query string never 500s.
        if ($request->filled('from')) {
            $from = $this->parseDateBoundary((string) $request->input('from'), startOfDay: true);
            if ($from !== null) {
                $query->where('created_at', '>=', $from);
            }
        }

        if ($request->filled('to')) {
            $to = $this->parseDateBoundary((string) $request->input('to'), startOfDay: false);
            if ($to !== null) {
                $query->where('created_at', '<=', $to);
            }
        }

        return $query;
    }

    /**
     * Parses an ISO-8601 date or datetime string into a
     * {@see CarbonImmutable}. When the input is a bare date
     * (`2026-05-26`), snaps to the start or end of that day based on
     * the boundary flag so range filters behave intuitively
     * (`from=2026-05-26&to=2026-05-26` means "everything on that
     * single day", not "nothing").
     *
     * Returns null on parse failure so the caller can simply skip
     * the filter and the request stays 200.
     */
    private function parseDateBoundary(string $raw, bool $startOfDay): ?CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::parse(trim($raw));
        } catch (Throwable) {
            return null;
        }

        // A bare date has no time component — parse() defaults it
        // to 00:00:00. Distinguish by checking the original string
        // for any time indicator; if there's none, snap to start /
        // end of day as appropriate.
        $hasTimeComponent = (bool) preg_match('/[Tt]|\s\d{2}:/', $raw);
        if (! $hasTimeComponent) {
            return $startOfDay ? $parsed->startOfDay() : $parsed->endOfDay();
        }

        return $parsed;
    }

    /**
     * Mirror of {@see AuditLogResource::TARGET_TYPE_MAP} resolution,
     * used by the CSV export so it doesn't need to instantiate a
     * Resource per row (Resource::toArray is heavier than a static
     * lookup for the 50k-row export case).
     */
    private function shortTargetType(?string $fqcn): ?string
    {
        if ($fqcn === null || $fqcn === '') {
            return null;
        }

        $reverse = array_flip(AuditLogResource::TARGET_TYPE_MAP);
        if (isset($reverse[$fqcn])) {
            return $reverse[$fqcn];
        }

        $basename = class_basename($fqcn);

        return strtolower((string) preg_replace('/(?<!^)([A-Z])/', '_$1', $basename));
    }
}
