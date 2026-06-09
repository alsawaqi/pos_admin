/**
 * Typed client for the admin platform Sales Report (v2 #16 + #19).
 *
 * No company_uuid → platform-wide (dashboard graphs). With a
 * company_uuid → that merchant only (per-merchant Sales tab).
 *
 * Mirrors {@link \App\Http\Controllers\Api\Admin\SalesReportController}.
 * Money fields are decimal-3 OMR strings.
 */

import { apiGet } from '@/lib/api';

export interface AdminSalesQuery {
    /** ISO date or datetime. Bare dates snap to start-of-day server-side. */
    from?: string;
    /** ISO date or datetime. Bare dates snap to end-of-day server-side. */
    to?: string;
    company_uuid?: string;
    [key: string]: string | number | boolean | null | undefined;
}

export interface AdminSalesReport {
    window: { from: string; to: string; company_id: number | null };
    headline: {
        gross_sales: string;
        net_sales: string;
        discount_total: string;
        tax_total: string;
        refunds_total: string;
        order_count: number;
        refund_count: number;
        avg_ticket: string;
    };
    sales_trend: { date: string; gross: string; count: number }[];
    top_merchants: { company_uuid: string; company_name: string; gross: string; count: number }[];
    by_branch: { branch_id: number; branch_name: string; gross: string; count: number }[];
    by_order_type: { type: string; gross: string; count: number }[];
    by_payment_method: { method: string; amount: string; count: number }[];
}

export function getAdminSalesReport(query: AdminSalesQuery = {}): Promise<{ data: AdminSalesReport }> {
    return apiGet<{ data: AdminSalesReport }>('/admin/api/v1/sales-report', { query });
}
