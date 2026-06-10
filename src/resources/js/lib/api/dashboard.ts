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
        /** Heartbeat within the last 5 minutes. */
        online: number;
        /** Assigned to a branch but heartbeat stale (or never seen). */
        offline_assigned: number;
        /** Last reported battery below 20%. */
        low_battery: number;
    };
    recent_merchants: DashboardRecentMerchant[];
    /** Last 20 rows from pos_audit_logs in the same shape the Audit Log viewer uses. */
    recent_activity: AuditLogEntry[];
    /**
     * Today's successful charity round-up donations. Present only
     * when the admin holds reports.view (money stays out of the
     * ungated landing payload).
     */
    roundup_today?: {
        /** Decimal-3 OMR string. */
        total: string;
        count: number;
    };
    /** Soft POS payments awaiting bank reconciliation. reports.view only. */
    reconciliation_pending?: {
        count: number;
        /** Decimal-3 OMR string. */
        amount: string;
    };
}

export interface DashboardSummaryResponse {
    data: DashboardSummary;
}

export function getDashboardSummary(): Promise<DashboardSummaryResponse> {
    return apiGet<DashboardSummaryResponse>('/admin/api/v1/dashboard/summary');
}
