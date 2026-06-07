/**
 * Typed client for the admin audit log endpoints
 * (blueprint §4.7 — Sprint 1.5).
 *
 *  - listAuditLogs() pulls a paginated, filtered page for the table.
 *  - buildAuditLogExportUrl() returns a same-filters CSV URL so the
 *    "Export CSV" button can window.open() it (a normal navigation
 *    triggers the file-save dialog without us needing to handle
 *    blobs/streams in JS).
 *
 * The query-string shape mirrors {@link \App\Http\Controllers\Api\Admin\AuditLogsController}
 * — keep these two files in sync when adding a new filter.
 */

import { apiGet } from '@/lib/api';
import type { PaginationLinks, PaginationMeta } from '@/lib/api/merchants';

/**
 * Short target-type identifier — same enum used in the server-side
 * AuditLogResource. Add new entries here AND in TARGET_TYPE_MAP on
 * the resource at the same time.
 */
export type AuditLogTargetType =
    | 'company'
    | 'branch'
    | 'device'
    | 'user'
    // The map's "fallback" branch can also emit snake_case basenames
    // for less-common morph targets — e.g. these write-paths that the
    // backend already audits today:
    | 'company_document'
    | 'business_activity'
    | 'device_make'
    | 'device_model'
    | string;

/**
 * One row as rendered by the table. JSON blobs are loose `unknown`
 * because event payloads vary (a device.assigned row has different
 * keys than a portal_user.invited row); the diff drawer renders
 * them generically via JSON.stringify.
 */
export interface AuditLogEntry {
    id: number;
    event: string;
    occurred_at: string | null;
    ip_address: string | null;
    user_agent: string | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    metadata: Record<string, unknown> | null;
    target_type: AuditLogTargetType | null;
    target_id: number | null;
    actor: { id: number; name: string; email: string } | null;
    company: { id: number; uuid: string; name: string; name_ar: string | null } | null;
    branch: { id: number; uuid: string; name: string } | null;
}

export interface PaginatedAuditLogs {
    data: AuditLogEntry[];
    meta: PaginationMeta;
    links: PaginationLinks;
}

/**
 * Every filter is optional — the table opens to "everything, newest
 * first" by default. Index signature lets the existing apiGet query
 * serialiser drop undefined / empty values.
 */
export interface AuditLogsQuery {
    page?: number;
    per_page?: number;
    actor_id?: number;
    event?: string;
    target_type?: AuditLogTargetType | '';
    company_uuid?: string;
    branch_uuid?: string;
    /** ISO date or datetime. Bare dates snap to start-of-day server-side. */
    from?: string;
    /** ISO date or datetime. Bare dates snap to end-of-day server-side. */
    to?: string;
    [key: string]: string | number | boolean | null | undefined;
}

export function listAuditLogs(query: AuditLogsQuery = {}): Promise<PaginatedAuditLogs> {
    return apiGet<PaginatedAuditLogs>('/admin/api/v1/audit-logs', { query });
}

/**
 * Builds the absolute URL for the CSV export, with the same filter
 * params as the on-screen list — call this from a `window.open()` /
 * anchor href so the browser handles the file download natively.
 *
 * We don't use apiGet for this because the response is a streamed
 * binary file, not JSON, and we want the browser to deal with the
 * Content-Disposition header rather than buffering the bytes into
 * a Blob in JS memory.
 */
export function buildAuditLogExportUrl(query: AuditLogsQuery = {}): string {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(query)) {
        if (value === undefined || value === null || value === '') {
            continue;
        }
        params.set(key, String(value));
    }

    const qs = params.toString();
    return `/admin/api/v1/audit-logs/export.csv${qs ? `?${qs}` : ''}`;
}
