/**
 * Typed client for Phase 5 ADVERTISER billing (invoice-first).
 *
 * Bills are metered from DELIVERED impressions (pos_marketing_impressions,
 * replay-guarded — over-billing is impossible) and priced from the
 * advertiser's ACTIVE rate card; everything is snapshot onto the invoice.
 * Money fields are decimal-3 OMR strings. Server gates: reports.view reads,
 * settings.manage mutations — the UI mirrors it.
 */

import { apiGet, apiPost } from '@/lib/api';

export type AdPricingModel = 'per_day_per_screen' | 'per_thousand_impressions';
export type AdInvoiceStatus = 'issued' | 'paid' | 'void';

export interface AdPendingRow {
    advertiser_id: number;
    advertiser_name: string;
    brand_name: string;
    plays: number;
    play_seconds: number;
    screen_days: number;
    pricing_model: AdPricingModel | null;
    rate: string | null;
    estimated_amount: string | null;
    has_overlapping_invoice: boolean;
}

export interface AdInvoiceRow {
    uuid: string;
    advertiser_id: number;
    advertiser_name: string | null;
    brand_name: string | null;
    period_from: string;
    period_to: string;
    status: AdInvoiceStatus;
    pricing_model: AdPricingModel;
    rate: string;
    impressions_count: number;
    play_seconds: number;
    screen_days: number;
    amount: string;
    note: string | null;
    paid_at: string | null;
    created_at: string;
}

export interface AdInvoiceLine {
    branch_id: number | null;
    branch_name: string;
    plays: number;
    play_seconds: number;
    screen_days: number;
    amount: string;
}

export interface AdRateCardRow {
    id: number;
    uuid: string;
    advertiser_id: number;
    pricing_model: AdPricingModel;
    rate: string;
    is_active: boolean;
    notes: string | null;
    created_at: string;
}

export function fetchAdPending(from: string, to: string): Promise<{ data: AdPendingRow[] }> {
    return apiGet(`/admin/api/v1/ad-billing/pending?from=${from}&to=${to}`);
}

export interface AdInvoicePage {
    data: AdInvoiceRow[];
    current_page: number;
    last_page: number;
    total: number;
}

export function fetchAdInvoices(status?: string, page = 1): Promise<AdInvoicePage> {
    const params = new URLSearchParams();
    if (status) params.set('status', status);
    params.set('page', String(page));
    return apiGet(`/admin/api/v1/ad-billing/invoices?${params.toString()}`);
}

export function fetchAdInvoiceLines(uuid: string): Promise<{ data: AdInvoiceLine[] }> {
    return apiGet(`/admin/api/v1/ad-billing/invoices/${uuid}/lines`);
}

export function fetchAdRateCards(advertiserId: number): Promise<{ data: AdRateCardRow[] }> {
    return apiGet(`/admin/api/v1/ad-billing/rate-cards?advertiser_id=${advertiserId}`);
}

export function setAdRateCard(payload: { advertiser_id: number; pricing_model: AdPricingModel; rate: string; notes?: string }): Promise<{ data: AdRateCardRow }> {
    return apiPost('/admin/api/v1/ad-billing/rate-cards', payload);
}

export function issueAdInvoice(payload: { advertiser_id: number; from: string; to: string; note?: string }): Promise<{ data: AdInvoiceRow }> {
    return apiPost('/admin/api/v1/ad-billing/invoices', payload);
}

export function markAdInvoicePaid(uuid: string): Promise<{ data: AdInvoiceRow }> {
    return apiPost(`/admin/api/v1/ad-billing/invoices/${uuid}/mark-paid`, {});
}

export function voidAdInvoice(uuid: string, reason?: string): Promise<{ data: AdInvoiceRow }> {
    return apiPost(`/admin/api/v1/ad-billing/invoices/${uuid}/void`, { reason: reason ?? null });
}
