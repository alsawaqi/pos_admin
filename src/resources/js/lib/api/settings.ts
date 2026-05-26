/**
 * Typed client for the platform Settings endpoints.
 *
 *   GET   /admin/api/v1/settings — full catalogue
 *   PATCH /admin/api/v1/settings — bulk update key→value pairs
 *
 * Mirrors {@link \App\Http\Controllers\Api\Admin\SettingsController}
 * and the {@link \App\Models\Setting} TYPE_* constants.
 */

import { apiGet, apiPatch, type JsonValue } from '@/lib/api';

export type SettingType =
    | 'string'
    | 'integer'
    | 'boolean'
    | 'select'
    | 'multiselect'
    | 'datetime'
    | 'textarea'
    | 'email_list';

export interface SettingOption {
    value: string | number;
    label_en: string;
    label_ar: string;
}

export interface PlatformSetting {
    key: string;
    /** Raw value — the scalar / array / null the admin actually sees. */
    value: string | number | boolean | string[] | null;
    type: SettingType;
    group_key: string;
    label_en: string;
    label_ar: string | null;
    help_en: string | null;
    help_ar: string | null;
    options: SettingOption[] | null;
    display_order: number;
}

export interface SettingsResponse {
    data: PlatformSetting[];
}

/** GET /admin/api/v1/settings — full catalogue, server-sorted. */
export function getSettings(): Promise<SettingsResponse> {
    return apiGet<SettingsResponse>('/admin/api/v1/settings');
}

/** PATCH /admin/api/v1/settings — bulk update key→value pairs. */
export function updateSettings(
    changes: Record<string, unknown>,
): Promise<SettingsResponse> {
    return apiPatch<SettingsResponse>(
        '/admin/api/v1/settings',
        { settings: changes } as unknown as JsonValue,
    );
}
