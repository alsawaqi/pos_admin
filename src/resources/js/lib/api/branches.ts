import { apiDelete, apiGet, apiPatch, apiPost, type JsonValue } from '@/lib/api';
import type { PaginationLinks, PaginationMeta } from '@/lib/api/merchants';

export type BranchStatus = 'active' | 'inactive';

export type BranchOrderType = 'quick' | 'dine_in' | 'to_go' | 'delivery' | 'car';

export interface BranchOpeningHourEntry {
    open?: string | null;
    close?: string | null;
    closed?: boolean;
}

export type BranchOpeningHours = Record<string, BranchOpeningHourEntry>;

export interface BranchCompanySummary {
    id: number;
    uuid: string;
    name: string;
    name_ar: string | null;
}

export interface BranchListItem {
    id: number;
    uuid: string;
    company_id: number;
    company?: BranchCompanySummary;
    name: string;
    name_ar: string | null;
    code: string | null;
    manager_name: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    country_id: number | null;
    region_id: number | null;
    district_id: number | null;
    city_id: number | null;
    latitude: number | null;
    longitude: number | null;
    geofence_radius_m: number;
    opening_hours_json: BranchOpeningHours | null;
    default_order_type: BranchOrderType | null;
    status: BranchStatus | null;
    settings: Record<string, unknown> | null;
    devices_count?: number;
    created_at: string | null;
    updated_at: string | null;
}

export type BranchDetail = BranchListItem;

export interface PaginatedBranches {
    data: BranchListItem[];
    meta: PaginationMeta;
    links: PaginationLinks;
}

export interface CreateBranchPayload {
    company_id: number;
    name: string;
    name_ar?: string | null;
    code?: string | null;
    manager_name?: string | null;
    phone?: string | null;
    email?: string | null;
    address?: string | null;
    country_id?: number | null;
    region_id?: number | null;
    district_id?: number | null;
    city_id?: number | null;
    latitude: number;
    longitude: number;
    geofence_radius_m?: number;
    opening_hours_json?: BranchOpeningHours | null;
    default_order_type?: BranchOrderType;
    status?: BranchStatus;
    settings?: Record<string, unknown> | null;
}

export type UpdateBranchPayload = Partial<Omit<CreateBranchPayload, 'company_id'>>;

export interface BranchesQuery {
    page?: number;
    per_page?: number;
    company_id?: number;
    status?: BranchStatus;
    search?: string;
    [key: string]: string | number | boolean | null | undefined;
}

export function listBranches(query: BranchesQuery = {}): Promise<PaginatedBranches> {
    return apiGet<PaginatedBranches>('/admin/api/v1/branches', { query });
}

export function getBranch(uuid: string): Promise<{ data: BranchDetail }> {
    return apiGet<{ data: BranchDetail }>(`/admin/api/v1/branches/${uuid}`);
}

export function createBranch(payload: CreateBranchPayload): Promise<{ data: BranchDetail }> {
    return apiPost<{ data: BranchDetail }>('/admin/api/v1/branches', payload as unknown as JsonValue);
}

export function updateBranch(uuid: string, payload: UpdateBranchPayload): Promise<{ data: BranchDetail }> {
    return apiPatch<{ data: BranchDetail }>(
        `/admin/api/v1/branches/${uuid}`,
        payload as unknown as JsonValue,
    );
}

/**
 * DELETE /admin/api/v1/branches/{uuid} — soft-delete a branch.
 * Server returns 204 on success, 409 (with `{message: ...}`)
 * when the branch still has active devices assigned, or 403 when
 * the caller lacks BranchesDelete.
 */
export function deleteBranch(uuid: string): Promise<void> {
    return apiDelete<void>(`/admin/api/v1/branches/${uuid}`);
}
