/**
 * Typed client for the P-F7 Pending Reconciliation approval queue.
 * Mirrors {@link \App\Http\Controllers\Api\Admin\PendingReconciliationController}.
 *
 * Orders whose force-recorded Soft POS tenders sit pending_reconciliation:
 * approving fires the DEFERRED money effects (commission split + charity
 * round-up forwarding); rejecting records that the money never arrived.
 */

import { apiGet, apiPost } from '@/lib/api';
import type { PaginationMeta } from '@/lib/api/merchants';

export interface PendingTenderRow {
    id: number;
    method: string;
    amount: string;
    softpos_reference: string | null;
    softpos_auth_code: string | null;
    /** The raw Soft POS verdict recorded on the tender (e.g. 'timeout'). */
    bank_verdict: string | null;
    captured_at: string | null;
}

export interface PendingReconciliationOrderRow {
    id: number;
    uuid: string;
    company: { uuid: string; name: string; name_ar: string | null } | null;
    branch: { uuid: string; name: string } | null;
    device_name: string | null;
    opened_at: string | null;
    grand_total: string;
    pending_total: string;
    tenders: PendingTenderRow[];
    roundup_total: string | null;
    roundup_unforwarded: number;
}

export interface PendingReconciliationList {
    data: PendingReconciliationOrderRow[];
    meta: PaginationMeta;
    totals: { orders: number; pending_amount: string };
    date: string;
}

export interface DeferredEffectsSummary {
    orders_settled: number[];
    orders_still_pending: number[];
    commissions_recorded: number;
    donations_forwarded: number;
    donation_forward_failures: { order_id: number; donation_id: number }[];
}

export interface ApproveResult {
    data: {
        orders_approved: number;
        payments_reconciled: number;
        effects: DeferredEffectsSummary;
    };
}

export interface RejectResult {
    data: {
        orders_rejected: number;
        payments_failed: number;
    };
}

export function listPendingReconciliation(query: {
    date?: string;
    page?: number;
    per_page?: number;
} = {}): Promise<PendingReconciliationList> {
    return apiGet<PendingReconciliationList>('/admin/api/v1/pending-reconciliation', { query });
}

export function approvePendingReconciliation(orderIds: number[]): Promise<ApproveResult> {
    return apiPost<ApproveResult>('/admin/api/v1/pending-reconciliation/approve', { order_ids: orderIds });
}

export function rejectPendingReconciliation(orderIds: number[]): Promise<RejectResult> {
    return apiPost<RejectResult>('/admin/api/v1/pending-reconciliation/reject', { order_ids: orderIds });
}
