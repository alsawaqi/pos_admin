/**
 * Typed client for the admin commission-settlement workflow.
 *
 * Reconciles a merchant's unsettled CARD sales against the bank's ACTUAL fee
 * and finalises the exact merchant net (estimate → settled). preview() shows
 * what a settlement would touch + the current estimate; create() applies it;
 * reverse() undoes one (while its sales aren't yet paid out).
 *
 * Mirrors {@link \App\Http\Controllers\Api\Admin\CommissionSettlementController}
 * + {@link \App\Http\Resources\Admin\CommissionSettlementResource}. Money fields
 * are decimal-3 OMR strings.
 *
 *   GET  commission-settlements[?company_uuid=&status=]   → list (reports.view)
 *   GET  commission-settlements/preview                   → summary (settings.manage)
 *   POST commission-settlements                           → apply (settings.manage)
 *   POST commission-settlements/{uuid}/reverse            → undo (settings.manage)
 */

import { apiGet, apiPost } from '@/lib/api';
import type { PaginationMeta } from '@/lib/api/merchants';

export type CommissionSettlementStatus = 'applied' | 'reversed';
export type CommissionSettlementSource = 'manual' | 'bank_file';

export interface CommissionSettlementRow {
    id: number;
    uuid: string;
    company_id: number;
    /** Present on the index join; null on a freshly returned single row. */
    company_uuid: string | null;
    company_name: string | null;
    branch_id: number | null;
    source: CommissionSettlementSource;
    bank_id: number | null;
    /** 'YYYY-MM-DD' or null. */
    statement_date: string | null;
    period_from: string | null;
    period_to: string | null;
    /** All decimal-3 OMR strings. */
    card_gross: string;
    estimated_bank: string;
    actual_bank: string;
    platform_total: string;
    merchant_net: string;
    variance: string;
    orders_count: number;
    status: CommissionSettlementStatus;
    note: string | null;
    reversed_at: string | null;
    created_at: string | null;
}

/** What a settlement would touch — shown before the admin enters the actual fee. */
export interface CommissionSettlementPreview {
    orders_count: number;
    card_gross: string;
    estimated_bank: string;
    platform_total: string;
    merchant_net_estimated: string;
}

export interface PaginatedCommissionSettlements {
    data: CommissionSettlementRow[];
    meta: PaginationMeta;
}

export interface SettlementScope {
    companyUuid: string;
    /** 'YYYY-MM-DD'. */
    from: string;
    /** 'YYYY-MM-DD'. */
    to: string;
    branchUuid?: string;
}

export interface CreateSettlementPayload extends SettlementScope {
    /** OMR decimal string the admin entered (the real bank fee for the batch). */
    actualBank: string;
    statementDate?: string;
    note?: string;
}

export function listCommissionSettlements(query: { companyUuid?: string; status?: CommissionSettlementStatus } = {}): Promise<PaginatedCommissionSettlements> {
    return apiGet<PaginatedCommissionSettlements>('/admin/api/v1/commission-settlements', {
        query: { company_uuid: query.companyUuid, status: query.status },
    });
}

export function previewCommissionSettlement({ companyUuid, from, to, branchUuid }: SettlementScope): Promise<{ data: CommissionSettlementPreview }> {
    return apiGet<{ data: CommissionSettlementPreview }>('/admin/api/v1/commission-settlements/preview', {
        query: { company_uuid: companyUuid, from, to, branch_uuid: branchUuid },
    });
}

export function createCommissionSettlement({ companyUuid, from, to, branchUuid, actualBank, statementDate, note }: CreateSettlementPayload): Promise<{ data: CommissionSettlementRow }> {
    return apiPost<{ data: CommissionSettlementRow }>('/admin/api/v1/commission-settlements', {
        company_uuid: companyUuid,
        from,
        to,
        branch_uuid: branchUuid || null,
        actual_bank: actualBank,
        statement_date: statementDate || null,
        note: note || null,
    });
}

export function reverseCommissionSettlement(uuid: string): Promise<{ data: CommissionSettlementRow }> {
    return apiPost<{ data: CommissionSettlementRow }>(`/admin/api/v1/commission-settlements/${uuid}/reverse`);
}

// ── Pending settlement drill-down (the daily to-do) ─────────────────────────

export interface PendingBranch {
    branch_uuid: string;
    branch_name: string;
    pending_orders: number;
    /** decimal-3 OMR string — estimated merchant take for the unsettled sales. */
    pending_net: string;
}

export interface PendingMerchant {
    company_uuid: string;
    company_name: string;
    pending_orders: number;
    pending_net: string;
    branches: PendingBranch[];
}

export function listPendingSettlement(from: string, to: string): Promise<{ data: PendingMerchant[] }> {
    return apiGet<{ data: PendingMerchant[] }>('/admin/api/v1/commission-settlements/pending', { query: { from, to } });
}

// ── Per-order reconciliation worklist ───────────────────────────────────────

export interface SettlementOrderTender {
    /** decimal-3 OMR string. */
    amount: string;
    terminal_id: string | null;
    auth_code: string | null;
    reference: string | null;
    captured_at: string | null;
}

export interface SettlementOrderRow {
    order_uuid: string;
    receipt_number: string | null;
    occurred_at: string | null;
    /** All decimal-3 OMR strings. card_amount is the commission base (sale, not the round-up). */
    grand_total: string;
    card_amount: string;
    roundup: string;
    estimated_bank: string;
    estimated_platform: string;
    estimated_merchant_net: string;
    /** Card sale with a bank fee to match. Cash sales (false) are review-only. */
    needs_reconciliation: boolean;
    is_settled: boolean;
    is_paid_out: boolean;
    settled_bank: string | null;
    settled_merchant_net: string | null;
    tenders: SettlementOrderTender[];
}

export type SettlementOrderStatus = 'unsettled' | 'settled' | 'all';
/** 'card' = the bank-fee to-do (default); 'all' also shows cash sales for review. */
export type SettlementPaymentMethod = 'card' | 'all';

export function listSettlementOrders(query: { companyUuid: string; branchUuid: string; from: string; to: string; status?: SettlementOrderStatus; paymentMethod?: SettlementPaymentMethod }): Promise<{ data: SettlementOrderRow[] }> {
    return apiGet<{ data: SettlementOrderRow[] }>('/admin/api/v1/commission-settlements/orders', {
        query: { company_uuid: query.companyUuid, branch_uuid: query.branchUuid, from: query.from, to: query.to, status: query.status, payment_method: query.paymentMethod },
    });
}

export interface SettleOrderLine {
    order_uuid: string;
    /** OMR decimal string — the actual bank fee for THIS order. */
    actual_bank: string;
}

export function settleCommissionOrders(payload: { companyUuid: string; branchUuid?: string; orders: SettleOrderLine[]; note?: string }): Promise<{ data: CommissionSettlementRow }> {
    return apiPost<{ data: CommissionSettlementRow }>('/admin/api/v1/commission-settlements/orders', {
        company_uuid: payload.companyUuid,
        branch_uuid: payload.branchUuid || null,
        // Cast to plain JSON records (a named interface lacks the index
        // signature apiPost's JsonValue expects).
        orders: payload.orders.map((o) => ({ order_uuid: o.order_uuid, actual_bank: o.actual_bank }) as Record<string, string>),
        note: payload.note || null,
    });
}
