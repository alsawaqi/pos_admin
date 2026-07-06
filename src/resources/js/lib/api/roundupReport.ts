/**
 * Typed client for the admin platform Round-Up Donations Report.
 *
 * Per-merchant charity round-up totals over the window — how much each
 * merchant's customers rounded up to charity, plus platform-wide headline
 * counts (donations, pending, failed, merchant count).
 *
 * No company_uuid → all merchants (sorted by total_raised desc). With a
 * company_uuid → that merchant only.
 *
 * Mirrors {@link \App\Http\Controllers\Api\Admin\RoundUpReportController}.
 * Money fields are decimal-3 OMR strings.
 */

import { apiGet } from '@/lib/api';

export interface AdminRoundUpQuery {
    /** ISO date or datetime. Bare dates snap to start-of-day server-side. */
    from?: string;
    /** ISO date or datetime. Bare dates snap to end-of-day server-side. */
    to?: string;
    /** Omit (or undefined) → all merchants. */
    companyUuid?: string;
}

export interface RoundUpMerchantRow {
    company_uuid: string;
    company_name: string;
    /** Decimal-3 OMR string — total round-up raised by this merchant. */
    total_raised: string;
    donation_count: number;
}

export interface RoundUpBranchRow {
    branch_id: number;
    /** Merchant branch name (from the shared pos_branches table). */
    branch_name: string;
    country: string | null;
    region: string | null;
    city: string | null;
    /** Decimal-3 OMR string — total round-up raised at this branch. */
    total_raised: string;
    donation_count: number;
}

export interface AdminRoundUpReport {
    window: { from: string; to: string; company_id: number | null };
    headline: {
        /** Decimal-3 OMR string — total round-up raised across all merchants. */
        total_raised: string;
        donation_count: number;
        pending_count: number;
        failed_count: number;
        num_merchants: number;
    };
    by_merchant: RoundUpMerchantRow[];
    by_branch: RoundUpBranchRow[];
}

export function getRoundUpReport({ from, to, companyUuid }: AdminRoundUpQuery = {}): Promise<{ data: AdminRoundUpReport }> {
    return apiGet<{ data: AdminRoundUpReport }>('/admin/api/v1/roundup-report', {
        query: { from, to, company_uuid: companyUuid },
    });
}
