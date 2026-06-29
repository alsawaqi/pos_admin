/**
 * TypeScript client for admin-driven advertiser onboarding
 * (Marketing → Advertisers). The `advertisers` row lives in the shared
 * charity_db (owned by the marketing-api app); pos_admin creates the account +
 * login, links it to a merchant, suspends, and resets the password.
 *
 * Endpoints (admin.php), all gated by marketing.advertisers.manage:
 *   GET   /admin/api/v1/marketing/advertisers
 *   POST  /admin/api/v1/marketing/advertisers
 *   PATCH /admin/api/v1/marketing/advertisers/{id}
 *   POST  /admin/api/v1/marketing/advertisers/{id}/reset-password
 */

import { apiGet, apiPatch, apiPost, type JsonValue } from '@/lib/api';
import type { CreateMerchantPayload, MerchantListItem, OwnerPayload } from '@/lib/api/merchants';

export type AdvertiserStatus = 'active' | 'suspended';

export interface Advertiser {
    id: number;
    name: string;
    brand_name: string;
    email: string;
    phone: string | null;
    status: AdvertiserStatus;
    is_merchant: boolean;
    company_id: number | null;
    company: { id: number; uuid: string; name: string } | null;
    category: string | null;
    created_at: string | null;
}

export interface CreateAdvertiserPayload {
    name: string;
    brand_name: string;
    email: string;
    password: string;
    phone?: string | null;
    is_merchant?: boolean;
    company_id?: number | null;
    category?: string | null;
}

/**
 * Onboard a NEW advertising-only company + its portal login in one step. The
 * company half reuses the merchant create shape (minus commission); the login
 * lives under `account`. Used by the advertiser onboarding wizard when the
 * advertiser is NOT an existing POS merchant.
 */
export interface CreateAdvertiserCompanyPayload
    extends Pick<CreateMerchantPayload, 'name' | 'name_ar' | 'legal_name' | 'legal_name_ar' | 'compliance' | 'contact' | 'owners' | 'activities'> {
    account: {
        email: string;
        password: string;
        brand_name?: string | null;
        contact_name?: string | null;
        phone?: string | null;
        category?: string | null;
    };
}

export interface UpdateAdvertiserPayload {
    name?: string;
    brand_name?: string;
    phone?: string | null;
    status?: AdvertiserStatus;
    is_merchant?: boolean;
    company_id?: number | null;
    category?: string | null;
}

export interface AdvertisersQuery {
    search?: string;
    status?: AdvertiserStatus;
    merchants_only?: boolean;
    [key: string]: string | number | boolean | null | undefined;
}

export function listAdvertisers(query: AdvertisersQuery = {}): Promise<{ data: Advertiser[] }> {
    return apiGet<{ data: Advertiser[] }>('/admin/api/v1/marketing/advertisers', { query });
}

export function createAdvertiser(payload: CreateAdvertiserPayload): Promise<{ data: Advertiser }> {
    return apiPost<{ data: Advertiser }>('/admin/api/v1/marketing/advertisers', payload as unknown as JsonValue);
}

/** Onboard a brand-new advertising-only company together with its login. */
export function createAdvertiserCompany(payload: CreateAdvertiserCompanyPayload): Promise<{ data: Advertiser }> {
    return apiPost<{ data: Advertiser }>('/admin/api/v1/marketing/advertisers/with-company', payload as unknown as JsonValue);
}

export function updateAdvertiser(id: number, payload: UpdateAdvertiserPayload): Promise<{ data: Advertiser }> {
    return apiPatch<{ data: Advertiser }>(`/admin/api/v1/marketing/advertisers/${id}`, payload as unknown as JsonValue);
}

/** Returns the new plaintext password ONCE, for the admin to hand over. */
export function resetAdvertiserPassword(id: number): Promise<{ data: { password: string } }> {
    return apiPost<{ data: { password: string } }>(`/admin/api/v1/marketing/advertisers/${id}/reset-password`);
}

/** Companies for the merchant-link picker — reuses the admin merchants list. */
export function listMerchantCompanies(): Promise<{ data: MerchantListItem[] }> {
    return apiGet<{ data: MerchantListItem[] }>('/admin/api/v1/merchants');
}

// ---- Advertiser detail (the tabbed Show page) -----------------------------

export interface CompanyOwnerDetail {
    id?: number;
    full_name_en: string;
    full_name_ar: string | null;
    civil_id: string | null;
    nationality: string | null;
    phone: string | null;
    email: string | null;
    is_primary: boolean;
    ownership_percentage: number | null;
}

export interface AdvertiserCompanyDetail {
    id: number;
    uuid: string;
    is_advertiser_only: boolean;
    name: string;
    name_ar: string | null;
    legal_name: string | null;
    legal_name_ar: string | null;
    compliance: {
        cr_number: string | null;
        cr_issue_date: string | null;
        cr_expiry_date: string | null;
        establishment_date: string | null;
        tax_number: string | null;
        vat_number: string | null;
        vat_registered_at: string | null;
        chamber_of_commerce_number: string | null;
        municipality_license_number: string | null;
    };
    contact: { name: string | null; phone: string | null; email: string | null };
    owners: CompanyOwnerDetail[];
    activities: Array<{ id: number; code: string; name_en: string; name_ar: string; is_primary?: boolean }>;
    status: string | null;
}

export interface AdvertiserContentStats {
    total: number;
    pending: number;
    approved: number;
    rejected: number;
}

export interface AdvertiserDetail {
    id: number;
    name: string;
    brand_name: string;
    email: string;
    phone: string | null;
    status: AdvertiserStatus;
    is_merchant: boolean;
    company_id: number | null;
    category: string | null;
    created_at: string | null;
    company: AdvertiserCompanyDetail | null;
    content_stats: AdvertiserContentStats | null;
}

export interface UpdateAdvertiserCompanyPayload {
    name?: string;
    name_ar?: string | null;
    legal_name?: string | null;
    legal_name_ar?: string | null;
    compliance?: {
        cr_number: string;
        cr_issue_date?: string | null;
        cr_expiry_date?: string | null;
        establishment_date?: string | null;
        tax_number?: string | null;
        vat_number?: string | null;
        vat_registered_at?: string | null;
        chamber_of_commerce_number?: string | null;
        municipality_license_number?: string | null;
    };
    contact?: { name?: string | null; phone?: string | null; email?: string | null };
    owners?: OwnerPayload[];
}

export function getAdvertiser(id: number): Promise<{ data: AdvertiserDetail }> {
    return apiGet<{ data: AdvertiserDetail }>(`/admin/api/v1/marketing/advertisers/${id}`);
}

export function updateAdvertiserCompany(id: number, payload: UpdateAdvertiserCompanyPayload): Promise<{ data: AdvertiserDetail }> {
    return apiPatch<{ data: AdvertiserDetail }>(`/admin/api/v1/marketing/advertisers/${id}/company`, payload as unknown as JsonValue);
}

export function syncAdvertiserActivities(
    id: number,
    activities: Array<{ business_activity_id: number; is_primary?: boolean }>,
): Promise<{ data: AdvertiserDetail }> {
    return apiPatch<{ data: AdvertiserDetail }>(
        `/admin/api/v1/marketing/advertisers/${id}/activities`,
        { activities } as unknown as JsonValue,
    );
}
