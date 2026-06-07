/**
 * Typed client for the admin platform-wide Sales / Orders viewer.
 * Mirrors {@link \App\Http\Controllers\Api\Admin\OrdersController}.
 */

import { apiGet } from '@/lib/api';
import type { PaginationLinks, PaginationMeta } from '@/lib/api/merchants';

export interface AdminOrderRow {
    id: number;
    uuid: string;
    company: { uuid: string; name: string; name_ar: string | null } | null;
    branch: { uuid: string; name: string } | null;
    order_type: string | null;
    status: string | null;
    source: string | null;
    grand_total: string;
    opened_at: string | null;
    closed_at: string | null;
}

export interface PaginatedOrders {
    data: AdminOrderRow[];
    totals: { count: number; grand_total: string };
    meta: PaginationMeta;
    links: PaginationLinks;
}

export interface AdminOrdersQuery {
    page?: number;
    per_page?: number;
    company_uuid?: string;
    status?: string;
    /** ISO date or datetime. Bare dates snap to start-of-day server-side. */
    from?: string;
    /** ISO date or datetime. Bare dates snap to end-of-day server-side. */
    to?: string;
    [key: string]: string | number | boolean | null | undefined;
}

export function listAdminOrders(query: AdminOrdersQuery = {}): Promise<PaginatedOrders> {
    return apiGet<PaginatedOrders>('/admin/api/v1/orders', { query });
}
