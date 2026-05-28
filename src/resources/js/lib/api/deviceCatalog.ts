/**
 * TypeScript client for the Device Makes + Models catalogue admin
 * endpoints (Sprint 1.4). Used by the Settings → Device catalogue
 * page and by the Register Device cascading dropdowns.
 *
 * Endpoints (all nested under /admin/api/v1/):
 *   GET    /device-makes                              → listMakes
 *   POST   /device-makes                              → createMake
 *   PATCH  /device-makes/{id}                         → updateMake
 *   DELETE /device-makes/{id}                         → deleteMake
 *   GET    /device-makes/{make}/models                → listModels
 *   POST   /device-makes/{make}/models                → createModel
 *   PATCH  /device-makes/{make}/models/{id}           → updateModel
 *   DELETE /device-makes/{make}/models/{id}           → deleteModel
 *
 * Permission: DeviceModelsManage required to mutate; list is open
 * to any authenticated admin so the Register Device dropdowns
 * populate for non-managers too.
 */

import { apiDelete, apiGet, apiPatch, apiPost, type JsonValue } from '@/lib/api';

export interface DeviceMake {
    id: number;
    name: string;
    display_order: number;
    is_active: boolean;
    // Present when the controller called withCount('models') —
    // used by the catalogue page chip "N models".
    models_count?: number;
    devices_count?: number;
    created_at: string | null;
    updated_at: string | null;
}

export interface DeviceModel {
    id: number;
    make_id: number;
    name: string;
    code: string | null;
    display_order: number;
    is_active: boolean;
    make?: { id: number; name: string };
    devices_count?: number;
    created_at: string | null;
    updated_at: string | null;
}

export interface DeviceMakePayload {
    name: string;
    display_order?: number;
    is_active?: boolean;
}

export interface DeviceModelPayload {
    name: string;
    code?: string | null;
    display_order?: number;
    is_active?: boolean;
}

export interface DeviceCatalogQuery {
    search?: string;
    // Sent by the Settings admin page so it can see deactivated
    // rows for management. Omitted by the Register Device form so
    // the dropdowns only show currently-usable options.
    include_inactive?: boolean;
    [key: string]: string | number | boolean | null | undefined;
}

// ---- Makes ----------------------------------------------------------

export function listMakes(query: DeviceCatalogQuery = {}): Promise<{ data: DeviceMake[] }> {
    return apiGet<{ data: DeviceMake[] }>('/admin/api/v1/device-makes', { query });
}

export function createMake(payload: DeviceMakePayload): Promise<{ data: DeviceMake }> {
    return apiPost<{ data: DeviceMake }>(
        '/admin/api/v1/device-makes',
        payload as unknown as JsonValue,
    );
}

export function updateMake(id: number, payload: Partial<DeviceMakePayload>): Promise<{ data: DeviceMake }> {
    return apiPatch<{ data: DeviceMake }>(
        `/admin/api/v1/device-makes/${id}`,
        payload as unknown as JsonValue,
    );
}

export function deleteMake(id: number): Promise<void> {
    return apiDelete<void>(`/admin/api/v1/device-makes/${id}`);
}

// ---- Models (nested under makes) -----------------------------------

export function listModels(
    makeId: number,
    query: DeviceCatalogQuery = {},
): Promise<{ data: DeviceModel[] }> {
    return apiGet<{ data: DeviceModel[] }>(
        `/admin/api/v1/device-makes/${makeId}/models`,
        { query },
    );
}

export function createModel(
    makeId: number,
    payload: DeviceModelPayload,
): Promise<{ data: DeviceModel }> {
    return apiPost<{ data: DeviceModel }>(
        `/admin/api/v1/device-makes/${makeId}/models`,
        payload as unknown as JsonValue,
    );
}

export function updateModel(
    makeId: number,
    modelId: number,
    payload: Partial<DeviceModelPayload>,
): Promise<{ data: DeviceModel }> {
    return apiPatch<{ data: DeviceModel }>(
        `/admin/api/v1/device-makes/${makeId}/models/${modelId}`,
        payload as unknown as JsonValue,
    );
}

export function deleteModel(makeId: number, modelId: number): Promise<void> {
    return apiDelete<void>(`/admin/api/v1/device-makes/${makeId}/models/${modelId}`);
}
