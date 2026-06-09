/**
 * Typed client for the admin platform Settlement Report (v2 #17).
 *
 * Per-merchant commission breakdown + platform totals over the window
 * (the money the platform settles to each merchant + its own revenue).
 *
 * No company_uuid → all merchants (sorted by merchant_net desc). With a
 * company_uuid → that merchant only.
 *
 * Mirrors {@link \App\Http\Controllers\Api\Admin\SettlementReportController}.
 * Money fields are decimal-3 OMR strings.
 */

import { apiGet } from '@/lib/api';

export interface AdminSettlementQuery {
    /** ISO date or datetime. Bare dates snap to start-of-day server-side. */
    from?: string;
    /** ISO date or datetime. Bare dates snap to end-of-day server-side. */
    to?: string;
    /** Omit (or undefined) → all merchants. */
    companyUuid?: string;
}

export interface SettlementMerchantRow {
    company_id: number;
    company_uuid: string;
    company_name: string;
    /** Decimal-3 OMR string. */
    gross: string;
    /** Decimal-3 OMR string. */
    platform: string;
    /** Decimal-3 OMR string. */
    bank: string;
    /** Decimal-3 OMR string. */
    other: string;
    /** Decimal-3 OMR string — what the platform owes this merchant. */
    merchant_net: string;
    num_sales: number;
}

export interface AdminSettlementReport {
    window: { from: string; to: string; company_id: number | null };
    headline: {
        /** Decimal-3 OMR string. */
        gross: string;
        /** Decimal-3 OMR string. */
        platform_revenue: string;
        /** Decimal-3 OMR string. */
        bank_total: string;
        /** Decimal-3 OMR string. */
        other_total: string;
        /** Decimal-3 OMR string — total owed across all merchants. */
        merchant_payable: string;
        num_sales: number;
        num_merchants: number;
    };
    by_merchant: SettlementMerchantRow[];
}

export function getSettlementReport({ from, to, companyUuid }: AdminSettlementQuery = {}): Promise<{ data: AdminSettlementReport }> {
    return apiGet<{ data: AdminSettlementReport }>('/admin/api/v1/settlement-report', {
        query: { from, to, company_uuid: companyUuid },
    });
}
