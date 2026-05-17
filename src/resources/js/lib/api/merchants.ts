import { apiDelete, apiGet, apiPatch, apiPost, apiRequest, type JsonValue } from '@/lib/api';

export type CompanyStatus = 'onboarding' | 'active' | 'inactive' | 'suspended';

export type DocumentType =
    | 'cr_certificate'
    | 'vat_certificate'
    | 'municipality_license'
    | 'chamber_certificate'
    | 'owner_id_card'
    | 'lease_agreement'
    | 'signature_authority'
    | 'bank_letter'
    | 'other';

export type DocumentVerificationStatus = 'pending' | 'verified' | 'rejected' | 'expired';

export interface BusinessActivity {
    id: number;
    code: string;
    name_en: string;
    name_ar: string;
    category: string;
    isic_code: string | null;
    description_en: string | null;
    description_ar: string | null;
    is_active: boolean;
    display_order: number;
    is_primary?: boolean;
}

export interface MerchantListItem {
    id: number;
    uuid: string;
    name: string;
    name_ar: string | null;
    legal_name: string | null;
    cr_number: string | null;
    vat_number: string | null;
    cr_expiry_date: string | null;
    contact: { name: string | null; phone: string | null; email: string | null };
    status: CompanyStatus | null;
    activated_at: string | null;
    suspended_at: string | null;
    default_currency: string;
    default_locale: string;
    branches_count?: number;
    devices_count?: number;
    documents_count?: number;
    created_at: string | null;
    updated_at: string | null;
}

export interface CompanyDocument {
    id: number;
    uuid: string;
    company_id: number;
    document_type: DocumentType;
    original_name: string;
    mime_type: string;
    size_bytes: number;
    sha256: string;
    verification_status: DocumentVerificationStatus;
    verified_at: string | null;
    verified_by_user_id: number | null;
    uploaded_by_user_id: number | null;
    rejection_reason: string | null;
    issued_at: string | null;
    expires_at: string | null;
    days_until_expiry: number | null;
    is_expired: boolean;
    notes: string | null;
    created_at: string | null;
}

export interface CompanyStatusHistoryEntry {
    id: number;
    from_status: CompanyStatus | null;
    to_status: CompanyStatus;
    changed_by_user_id: number | null;
    reason: string | null;
    metadata: Record<string, unknown> | null;
    changed_at: string | null;
}

export interface MerchantDetail extends MerchantListItem {
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
    owner: {
        full_name_en: string | null;
        full_name_ar: string | null;
        civil_id: string | null;
        nationality: string | null;
        phone: string | null;
        email: string | null;
    };
    suspension_reason: string | null;
    settings: Record<string, unknown> | null;
    notes: string | null;
    activities: BusinessActivity[];
    documents?: CompanyDocument[];
    status_history?: CompanyStatusHistoryEntry[];
    onboarded_by_user_id: number | null;
}

export interface PaginatedMerchants {
    data: MerchantListItem[];
    meta: PaginationMeta;
    links: PaginationLinks;
}

export interface PaginationMeta {
    current_page: number;
    from: number | null;
    last_page: number;
    per_page: number;
    to: number | null;
    total: number;
}

export interface PaginationLinks {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
}

export interface CreateMerchantPayload {
    name: string;
    name_ar?: string | null;
    legal_name?: string | null;
    legal_name_ar?: string | null;
    compliance: {
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
    contact: {
        name?: string | null;
        phone?: string | null;
        email?: string | null;
    };
    owner: {
        full_name_en: string;
        full_name_ar?: string | null;
        civil_id?: string | null;
        nationality?: string | null;
        phone?: string | null;
        email?: string | null;
    };
    activities?: Array<{ business_activity_id: number; is_primary?: boolean }>;
    default_currency?: string;
    default_locale?: string;
    status?: CompanyStatus;
    notes?: string | null;
}

export interface MerchantsQuery {
    page?: number;
    per_page?: number;
    search?: string;
    status?: CompanyStatus;
    onboarded_by?: number;
}

export function listMerchants(query: MerchantsQuery = {}): Promise<PaginatedMerchants> {
    return apiGet<PaginatedMerchants>('/admin/api/v1/merchants', { query });
}

export function createMerchant(payload: CreateMerchantPayload): Promise<{ data: MerchantDetail }> {
    return apiPost<{ data: MerchantDetail }>('/admin/api/v1/merchants', payload as unknown as JsonValue);
}

export function getMerchant(uuid: string): Promise<{ data: MerchantDetail }> {
    return apiGet<{ data: MerchantDetail }>(`/admin/api/v1/merchants/${uuid}`);
}

export function updateMerchant(uuid: string, payload: Partial<CreateMerchantPayload>): Promise<{ data: MerchantDetail }> {
    return apiPatch<{ data: MerchantDetail }>(
        `/admin/api/v1/merchants/${uuid}`,
        payload as unknown as JsonValue,
    );
}

export function transitionMerchantStatus(
    uuid: string,
    payload: { target_status: CompanyStatus; reason?: string },
): Promise<{ data: MerchantDetail }> {
    return apiPost<{ data: MerchantDetail }>(
        `/admin/api/v1/merchants/${uuid}/status`,
        payload as unknown as JsonValue,
    );
}

export function syncMerchantActivities(
    uuid: string,
    activities: Array<{ business_activity_id: number; is_primary?: boolean }>,
): Promise<{ data: BusinessActivity[] }> {
    return apiRequest<{ data: BusinessActivity[] }>(`/admin/api/v1/merchants/${uuid}/activities`, {
        method: 'PUT',
        body: { activities } as unknown as JsonValue,
    });
}

export function listBusinessActivities(query: { category?: string; search?: string } = {}): Promise<{ data: BusinessActivity[] }> {
    return apiGet<{ data: BusinessActivity[] }>('/admin/api/v1/business-activities', { query });
}

export function listMerchantDocuments(uuid: string): Promise<{ data: CompanyDocument[]; meta: PaginationMeta }> {
    return apiGet<{ data: CompanyDocument[]; meta: PaginationMeta }>(
        `/admin/api/v1/merchants/${uuid}/documents`,
        { query: { per_page: 100 } },
    );
}

export async function uploadMerchantDocument(
    uuid: string,
    file: File,
    documentType: DocumentType,
    options: { issued_at?: string; expires_at?: string; notes?: string } = {},
): Promise<{ data: CompanyDocument }> {
    const form = new FormData();
    form.append('document_type', documentType);
    form.append('file', file);
    if (options.issued_at) {
        form.append('issued_at', options.issued_at);
    }
    if (options.expires_at) {
        form.append('expires_at', options.expires_at);
    }
    if (options.notes) {
        form.append('notes', options.notes);
    }

    const csrfMeta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
    const response = await fetch(`/admin/api/v1/merchants/${uuid}/documents`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfMeta?.content ?? '',
        },
        body: form,
    });

    if (!response.ok) {
        const payload: unknown = await response.json().catch(() => null);
        throw Object.assign(new Error('Upload failed'), { status: response.status, payload });
    }

    return response.json() as Promise<{ data: CompanyDocument }>;
}

export function verifyMerchantDocument(
    merchantUuid: string,
    documentUuid: string,
    payload: { notes?: string } = {},
): Promise<{ data: CompanyDocument }> {
    return apiPost<{ data: CompanyDocument }>(
        `/admin/api/v1/merchants/${merchantUuid}/documents/${documentUuid}/verify`,
        payload as unknown as JsonValue,
    );
}

export function rejectMerchantDocument(
    merchantUuid: string,
    documentUuid: string,
    payload: { reason: string },
): Promise<{ data: CompanyDocument }> {
    return apiPost<{ data: CompanyDocument }>(
        `/admin/api/v1/merchants/${merchantUuid}/documents/${documentUuid}/reject`,
        payload as unknown as JsonValue,
    );
}

export function deleteMerchantDocument(merchantUuid: string, documentUuid: string): Promise<void> {
    return apiDelete<void>(`/admin/api/v1/merchants/${merchantUuid}/documents/${documentUuid}`);
}

export function merchantDocumentDownloadUrl(merchantUuid: string, documentUuid: string): string {
    return `/admin/api/v1/merchants/${merchantUuid}/documents/${documentUuid}/download`;
}
