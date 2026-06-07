/**
 * Typed client for the Admin Dashboard summary endpoint
 * (blueprint §4.8 — Slim Sprint 2).
 *
 * The dashboard renders everything from one round-trip so the
 * landing page has a single loading state. Anything not in this
 * payload is deferred (POS-app data, scalefusion telemetry).
 *
 * Mirrors {@link \App\Http\Controllers\Api\Admin\DashboardSummaryController}.
 */

import { apiGet } from '@/lib/api';
import type { AuditLogEntry } from '@/lib/api/auditLogs';
import type { CompanyStatus } from '@/lib/api/merchants';
import type { DeviceStatus } from '@/lib/api/devices';

/**
 * Stats for each merchant card on the dashboard's "Recent
 * onboarding" table. Lean shape (no full CompanyResource) — only
 * what that table cell needs.
 */
export interface DashboardRecentMerchant {
    id: number;
    uuid: string;
    name: string;
    name_ar: string | null;
    contact_name: string | null;
    status: CompanyStatus | null;
    branches_count: number;
    devices_count: number;
    created_at: string | null;
}

export interface DashboardSummary {
    companies: {
        total: number;
        /** Always includes every CompanyStatus value, zeroed when missing. */
        by_status: Record<CompanyStatus, number>;
    };
    branches: {
        total: number;
    };
    devices: {
        total: number;
        /** Always includes every DeviceStatus value, zeroed when missing. */
        by_status: Record<DeviceStatus, number>;
        /** Devices with branch_id IS NULL — drives the "needs placement" tile. */
        unassigned: number;
    };
    recent_merchants: DashboardRecentMerchant[];
    /** Last 20 rows from pos_audit_logs in the same shape the Audit Log viewer uses. */
    recent_activity: AuditLogEntry[];
}

export interface DashboardSummaryResponse {
    data: DashboardSummary;
}

export function getDashboardSummary(): Promise<DashboardSummaryResponse> {
    return apiGet<DashboardSummaryResponse>('/admin/api/v1/dashboard/summary');
}
