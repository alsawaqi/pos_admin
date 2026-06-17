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
