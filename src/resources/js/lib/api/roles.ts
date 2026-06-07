/**
 * Typed client for the Admin Roles + Permissions endpoints.
 *
 * Mirrors {@link \App\Http\Controllers\Api\Admin\RolesController}.
 * The catalog endpoint returns the grouped platform permission
 * tree with EN+AR labels for the editor checkbox grid.
 *
 * Identical shape to pos_merchant's lib/api/roles.ts on purpose
 * — both apps consume the same payload contract.
 */

import {
    apiDelete,
    apiGet,
    apiPatch,
    apiPost,
    type JsonValue,
} from '@/lib/api';

export interface Role {
    id: number;
    name: string;
    description: string | null;
    is_system: boolean;
    permissions: string[];
    user_count: number;
    created_at: string | null;
    updated_at: string | null;
}

export interface PermissionDescriptor {
    key: string;
    label_en: string;
    label_ar: string;
}

export interface PermissionGroup {
    key: string;
    label_en: string;
    label_ar: string;
    permissions: PermissionDescriptor[];
}

export interface CreateRolePayload {
    name: string;
    description?: string | null;
    permissions?: string[];
}

export interface UpdateRolePayload {
    name?: string;
    description?: string | null;
    permissions?: string[];
}

const ROLES_BASE = '/admin/api/v1/roles';

export function listRoles(): Promise<{ data: Role[] }> {
    return apiGet<{ data: Role[] }>(ROLES_BASE);
}

export function getPermissionCatalog(): Promise<{ data: PermissionGroup[] }> {
    return apiGet<{ data: PermissionGroup[] }>(`${ROLES_BASE}/catalog`);
}

export function createRole(payload: CreateRolePayload): Promise<{ data: Role }> {
    return apiPost<{ data: Role }>(ROLES_BASE, payload as unknown as JsonValue);
}

export function updateRole(
    id: number,
    payload: UpdateRolePayload,
): Promise<{ data: Role }> {
    return apiPatch<{ data: Role }>(
        `${ROLES_BASE}/${id}`,
        payload as unknown as JsonValue,
    );
}

export function deleteRole(id: number): Promise<void> {
    return apiDelete<void>(`${ROLES_BASE}/${id}`);
}

/** Replace a platform user's role list. */
export function assignRolesToPlatformUser(
    userId: number,
    roleNames: string[],
): Promise<{ data: unknown }> {
    return apiPatch<{ data: unknown }>(
        `/admin/api/v1/platform-team/${userId}/roles`,
        { roles: roleNames } as unknown as JsonValue,
    );
}
