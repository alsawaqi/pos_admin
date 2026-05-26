/**
 * Typed client for the Platform Team endpoints.
 *
 * Mirrors {@link \App\Http\Controllers\Api\Admin\PlatformTeamController}.
 * Auth happens server-side via direct permission checks
 * (PlatformUsers*) — no policy involved.
 *
 * One quirk: the invite response carries a one-shot
 * `plaintext_password` alongside the user data. The frontend MUST
 * surface this in a copy-once modal and never persist it. Reads
 * via getPlatformUser() / listPlatformTeam() omit the password
 * entirely.
 */

import { apiGet, apiPatch, apiPost, type JsonValue } from '@/lib/api';
import type { PaginationLinks, PaginationMeta } from '@/lib/api/merchants';

/** Status enum — mirrors {@see \App\Enums\UserStatus}. */
export type PlatformUserStatus = 'active' | 'inactive' | 'suspended';

/** Role identifiers — mirrors {@see \App\Enums\PlatformRole}. */
export type PlatformRoleName =
    | 'platform_super_admin'
    | 'onboarding_officer'
    | 'device_operations'
    | 'support'
    | 'finance_viewer';

export interface PlatformUser {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    status: PlatformUserStatus | null;
    user_type: 'platform_admin' | 'merchant' | null;
    role: PlatformRoleName | null;
    last_login_at: string | null;
    invited_at: string | null;
    invited_by_admin_id: number | null;
    created_at: string | null;
}

export interface PaginatedPlatformTeam {
    data: PlatformUser[];
    meta: PaginationMeta;
    links: PaginationLinks;
}

export interface InvitePlatformUserPayload {
    name: string;
    email: string;
    phone?: string | null;
    role: PlatformRoleName;
}

export interface InvitePlatformUserResponse {
    data: PlatformUser;
    /** Plaintext password generated server-side. Surface ONCE then forget. */
    plaintext_password: string;
}

export interface UpdatePlatformUserPayload {
    name?: string;
    phone?: string | null;
    role?: PlatformRoleName;
}

export interface PlatformTeamQuery {
    page?: number;
    per_page?: number;
    search?: string;
    status?: PlatformUserStatus;
    [key: string]: string | number | boolean | null | undefined;
}

export function listPlatformTeam(query: PlatformTeamQuery = {}): Promise<PaginatedPlatformTeam> {
    return apiGet<PaginatedPlatformTeam>('/admin/api/v1/platform-team', { query });
}

export function invitePlatformUser(payload: InvitePlatformUserPayload): Promise<InvitePlatformUserResponse> {
    return apiPost<InvitePlatformUserResponse>(
        '/admin/api/v1/platform-team',
        payload as unknown as JsonValue,
    );
}

export function updatePlatformUser(
    id: number,
    payload: UpdatePlatformUserPayload,
): Promise<{ data: PlatformUser }> {
    return apiPatch<{ data: PlatformUser }>(
        `/admin/api/v1/platform-team/${id}`,
        payload as unknown as JsonValue,
    );
}

export function suspendPlatformUser(id: number): Promise<{ data: PlatformUser }> {
    return apiPost<{ data: PlatformUser }>(
        `/admin/api/v1/platform-team/${id}/suspend`,
    );
}

export function reactivatePlatformUser(id: number): Promise<{ data: PlatformUser }> {
    return apiPost<{ data: PlatformUser }>(
        `/admin/api/v1/platform-team/${id}/reactivate`,
    );
}
