/**
 * Typed client for the admin commission-INVOICE workflow (Phase B).
 *
 * The reverse of payouts: for CASH / BANK_POS sales the money went straight to
 * the merchant, so the platform bills the merchant its commission cut. The admin
 * drills the merchants/branches that owe (pending), issues an invoice for a
 * window, then marks it paid (the merchant remitted) or voids it (releasing the
 * claimed rows).
 *
 * Mirrors {@link \App\Http\Controllers\Api\Admin\CommissionInvoicesController} +
 * {@link \App\Http\Resources\Admin\CommissionInvoiceResource}. Money fields are
 * decimal-3 OMR strings. The server is the source of truth for the permission
 * gate (reports.view read / settings.manage write); the UI mirrors it.
 *
 *   GET  commission-invoices[?company_uuid=&status=]   → list (reports.view)
 *   GET  commission-invoices/pending                   → to-bill drill (reports.view)
 *   GET  commission-invoices/{uuid}/lines              → statement (reports.view)
 *   POST commission-invoices                           → issue (settings.manage)
 *   POST commission-invoices/{uuid}/mark-paid          → mark paid (settings.manage)
 *   POST commission-invoices/{uuid}/void               → void + release (settings.manage)
 */

import { apiGet, apiPost } from '@/lib/api';
import type { PaginationMeta } from '@/lib/api/merchants';

export type CommissionInvoiceStatus = 'issued' | 'paid' | 'void';

export interface CommissionInvoiceRow {
    uuid: string;
    company_id: number;
    branch_id: number | null;
    /** Present on the index join; may be null on a freshly returned single row. */
    company_uuid: string | null;
    company_name: string | null;
    branch_name: string | null;
    /** ISO datetime. */
    period_from: string | null;
    period_to: string | null;
    status: CommissionInvoiceStatus;
    /** All decimal-3 OMR strings. */
    gross_amount: string;
    platform_amount: string;
    other_amount: string;
    /** What the merchant kept (informational). */
    merchant_amount: string;
    /** Decimal-3 OMR string — what the merchant owes the platform. */
    total_owed: string;
    sales_count: number;
    reference: string | null;
    note: string | null;
    paid_at: string | null;
    voided_at: string | null;
    created_at: string | null;
}

export interface PaginatedCommissionInvoices {
    data: CommissionInvoiceRow[];
    meta: PaginationMeta;
}

export interface CreateCommissionInvoicePayload {
    companyUuid: string;
    /** Optional — scope the invoice to one branch. */
    branchUuid?: string;
    /** 'YYYY-MM-DD'. */
    from: string;
    to: string;
}

export interface MarkInvoicePaidPayload {
    reference?: string;
    note?: string;
}

export function listCommissionInvoices(query: { companyUuid?: string; status?: CommissionInvoiceStatus } = {}): Promise<PaginatedCommissionInvoices> {
    return apiGet<PaginatedCommissionInvoices>('/admin/api/v1/commission-invoices', {
        query: { company_uuid: query.companyUuid, status: query.status },
    });
}

export function createCommissionInvoice({ companyUuid, branchUuid, from, to }: CreateCommissionInvoicePayload): Promise<{ data: CommissionInvoiceRow }> {
    return apiPost<{ data: CommissionInvoiceRow }>('/admin/api/v1/commission-invoices', {
        company_uuid: companyUuid,
        branch_uuid: branchUuid || null,
        from,
        to,
    });
}

export function markCommissionInvoicePaid(uuid: string, { reference, note }: MarkInvoicePaidPayload = {}): Promise<{ data: CommissionInvoiceRow }> {
    return apiPost<{ data: CommissionInvoiceRow }>(`/admin/api/v1/commission-invoices/${uuid}/mark-paid`, {
        reference: reference || null,
        note: note || null,
    });
}

/** Outcome of a batch mark-paid: how many were marked vs skipped (non-issued). */
export interface BatchMarkInvoicesResult {
    marked: number;
    skipped: number;
}

export function batchMarkCommissionInvoicesPaid(uuids: string[], { reference, note }: MarkInvoicePaidPayload = {}): Promise<{ data: BatchMarkInvoicesResult }> {
    return apiPost<{ data: BatchMarkInvoicesResult }>('/admin/api/v1/commission-invoices/batch-mark-paid', {
        invoice_uuids: uuids,
        reference: reference || null,
        note: note || null,
    });
}

export function voidCommissionInvoice(uuid: string): Promise<{ data: CommissionInvoiceRow }> {
    return apiPost<{ data: CommissionInvoiceRow }>(`/admin/api/v1/commission-invoices/${uuid}/void`);
}

/** A commission invoice's per-branch breakdown line (the statement detail). */
export interface CommissionInvoiceLine {
    branch_id: number;
    branch_name: string;
    /** All decimal-3 OMR strings. */
    gross: string;
    platform: string;
    other: string;
    merchant_kept: string;
    total_owed: string;
    num_sales: number;
}

export function getCommissionInvoiceLines(uuid: string): Promise<{ data: CommissionInvoiceLine[] }> {
    return apiGet<{ data: CommissionInvoiceLine[] }>(`/admin/api/v1/commission-invoices/${uuid}/lines`);
}

// ── Pending (the "who owes commission" drill) ───────────────────────────────

export interface PendingInvoiceBranch {
    branch_uuid: string;
    branch_name: string;
    pending_orders: number;
    /** Decimal-3 OMR string — commission owed on the branch's un-invoiced cash sales. */
    pending_owed: string;
}

export interface PendingInvoiceMerchant {
    company_uuid: string;
    company_name: string;
    pending_orders: number;
    pending_owed: string;
    branches: PendingInvoiceBranch[];
}

export function listPendingCommissionInvoices(from: string, to: string): Promise<{ data: PendingInvoiceMerchant[] }> {
    return apiGet<{ data: PendingInvoiceMerchant[] }>('/admin/api/v1/commission-invoices/pending', { query: { from, to } });
}
