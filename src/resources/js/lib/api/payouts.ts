/**
 * Typed client for the admin merchant payout workflow (v2 #17, Phase B).
 *
 * The stateful settlement counterpart to the read-only settlement report:
 * the platform creates a PENDING payout for a merchant over a date window,
 * then marks it paid (with a reference + note) or cancels it.
 *
 * Mirrors {@link \App\Http\Controllers\Api\Admin\PayoutsController} +
 * {@link \App\Http\Resources\Admin\PayoutResource}.
 *
 *   GET  payouts[?company_uuid=&status=]   → list (reports.view)
 *   POST payouts                           → create pending (settings.manage)
 *   POST payouts/{uuid}/mark-paid          → mark paid (settings.manage)
 *   POST payouts/{uuid}/cancel             → cancel + release (settings.manage)
 *
 * Money fields are decimal-3 OMR strings. The server is the source of truth
 * for the permission gate; the UI mirrors it on settings.manage.
 */

import { apiGet, apiPost } from '@/lib/api';
import type { PaginationMeta } from '@/lib/api/merchants';

export type PayoutStatus = 'pending' | 'paid' | 'cancelled';

export interface PayoutRow {
    uuid: string;
    company_id: number;
    /** Present on the index join; may be null on a freshly returned single row. */
    company_uuid: string | null;
    /** Present on the index join; may be null on a freshly returned single row. */
    company_name: string | null;
    /** ISO datetime. */
    period_from: string | null;
    /** ISO datetime. */
    period_to: string | null;
    status: PayoutStatus;
    /** Decimal-3 OMR string. */
    gross_amount: string;
    /** Decimal-3 OMR string. */
    platform_amount: string;
    /** Decimal-3 OMR string. */
    bank_amount: string;
    /** Decimal-3 OMR string. */
    other_amount: string;
    /** Decimal-3 OMR string — what the platform pays this merchant. */
    net_amount: string;
    sales_count: number;
    reference: string | null;
    note: string | null;
    /** ISO datetime once paid, else null. */
    paid_at: string | null;
    /** ISO datetime. */
    created_at: string | null;
}

export interface ListPayoutsQuery {
    /** Omit → all merchants. */
    companyUuid?: string;
    status?: PayoutStatus;
}

export interface PaginatedPayouts {
    data: PayoutRow[];
    meta: PaginationMeta;
}

export interface CreatePayoutPayload {
    companyUuid: string;
    /** 'YYYY-MM-DD'. */
    from: string;
    /** 'YYYY-MM-DD'. */
    to: string;
}

export interface MarkPaidPayload {
    reference?: string;
    note?: string;
}

export function listPayouts({ companyUuid, status }: ListPayoutsQuery = {}): Promise<PaginatedPayouts> {
    return apiGet<PaginatedPayouts>('/admin/api/v1/payouts', {
        query: { company_uuid: companyUuid, status },
    });
}

export function createPayout({ companyUuid, from, to }: CreatePayoutPayload): Promise<{ data: PayoutRow }> {
    return apiPost<{ data: PayoutRow }>('/admin/api/v1/payouts', {
        company_uuid: companyUuid,
        from,
        to,
    });
}

export function markPayoutPaid(uuid: string, { reference, note }: MarkPaidPayload = {}): Promise<{ data: PayoutRow }> {
    return apiPost<{ data: PayoutRow }>(`/admin/api/v1/payouts/${uuid}/mark-paid`, {
        reference: reference || null,
        note: note || null,
    });
}

export function cancelPayout(uuid: string): Promise<{ data: PayoutRow }> {
    return apiPost<{ data: PayoutRow }>(`/admin/api/v1/payouts/${uuid}/cancel`);
}
