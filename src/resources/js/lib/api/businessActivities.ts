/**
 * TypeScript client for the platform-wide Business Activities
 * catalogue. Read-only `listBusinessActivities` is already exposed
 * from `lib/api/merchants` (where the create wizard uses it); this
 * module adds the admin CRUD endpoints used by the
 * Settings → Business Activities page.
 *
 * Endpoint catalogue (admin.php):
 *   GET    /admin/api/v1/business-activities                  → list
 *   POST   /admin/api/v1/business-activities                  → create
 *   PATCH  /admin/api/v1/business-activities/{id}             → update
 *   DELETE /admin/api/v1/business-activities/{id}             → delete
 *
 * Permission: BusinessActivitiesManage required for the mutating
 * endpoints; the list is open to any authenticated admin so the
 * merchant wizard works for non-managers too.
 */

import { apiDelete, apiGet, apiPatch, apiPost, type JsonValue } from '@/lib/api';
import type { BusinessActivity } from '@/lib/api/merchants';

/**
 * Categories that group activities in the wizard's selector. Must
 * stay in sync with {@see \App\Enums\BusinessActivityCategory}.
 */
export type BusinessActivityCategory =
    | 'food_and_beverage'
    | 'retail'
    | 'services'
    | 'hospitality'
    | 'healthcare'
    | 'education'
    | 'other';

export interface BusinessActivityPayload {
    code: string;
    name_en: string;
    name_ar: string;
    category: BusinessActivityCategory;
    isic_code?: string | null;
    description_en?: string | null;
    description_ar?: string | null;
    is_active?: boolean;
    display_order?: number;
}

export interface BusinessActivitiesQuery {
    category?: BusinessActivityCategory;
    search?: string;
    // The Settings admin page sends include_inactive=1 to see
    // deactivated rows. The merchant wizard does not.
    include_inactive?: boolean;
    [key: string]: string | number | boolean | null | undefined;
}

/** GET /admin/api/v1/business-activities — admin-facing list (can include inactive). */
export function listAllBusinessActivities(
    query: BusinessActivitiesQuery = {},
): Promise<{ data: BusinessActivity[] }> {
    return apiGet<{ data: BusinessActivity[] }>('/admin/api/v1/business-activities', { query });
}

/** POST /admin/api/v1/business-activities — add a new activity. */
export function createBusinessActivity(
    payload: BusinessActivityPayload,
): Promise<{ data: BusinessActivity }> {
    return apiPost<{ data: BusinessActivity }>(
        '/admin/api/v1/business-activities',
        payload as unknown as JsonValue,
    );
}

/** PATCH /admin/api/v1/business-activities/{id} — edit a subset of fields. */
export function updateBusinessActivity(
    id: number,
    payload: Partial<BusinessActivityPayload>,
): Promise<{ data: BusinessActivity }> {
    return apiPatch<{ data: BusinessActivity }>(
        `/admin/api/v1/business-activities/${id}`,
        payload as unknown as JsonValue,
    );
}

/** DELETE /admin/api/v1/business-activities/{id} — hard delete. Throws 409 if the activity is still in use by any merchant. */
export function deleteBusinessActivity(id: number): Promise<void> {
    return apiDelete<void>(`/admin/api/v1/business-activities/${id}`);
}
