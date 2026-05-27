/**
 * TypeScript client for the Admin Portal Devices API.
 *
 * Mirrors the shape of `DeviceResource::toArray()` on the back-end —
 * any change there needs a matching update here or the types drift
 * and `vue-tsc` starts shouting. Functions are thin wrappers around
 * the centralised `apiGet/apiPost/apiPatch` helpers in `@/lib/api`,
 * which already handle CSRF refresh and 401/419 redirects.
 *
 * Endpoint catalogue (all routes registered in routes/admin.php):
 *   GET    /admin/api/v1/devices                            → listDevices
 *   POST   /admin/api/v1/devices                            → registerDevice
 *   GET    /admin/api/v1/devices/{uuid}                     → getDevice
 *   POST   /admin/api/v1/devices/{uuid}/assign              → assignDevice
 *   POST   /admin/api/v1/devices/{uuid}/unassign            → unassignDevice
 */

import { apiGet, apiPost, type JsonValue } from '@/lib/api';
import type { PaginationLinks, PaginationMeta } from '@/lib/api/merchants';

/**
 * The five lifecycle states a device can be in. Mirrors
 * {@see \App\Enums\DeviceStatus}. The blueprint's vocabulary
 * (Online/Offline/Locked) maps roughly onto Active/Inactive/Blocked.
 */
export type DeviceStatus = 'registered' | 'assigned' | 'active' | 'inactive' | 'blocked';

/**
 * The three hardware classes (blueprint §4.4.2). Mirrors
 * {@see \App\Enums\DeviceType}.
 */
export type DeviceType = 'fixed_pos' | 'handheld' | 'customer_tablet';

/** Small shape embedded inside DeviceListItem when the company relation is preloaded. */
export interface DeviceCompanySummary {
    id: number;
    uuid: string;
    name: string;
    name_ar: string | null;
}

/** Small shape embedded inside DeviceListItem when the branch relation is preloaded. */
export interface DeviceBranchSummary {
    id: number;
    uuid: string;
    name: string;
    name_ar: string | null;
    latitude: number | null;
    longitude: number | null;
    geofence_radius_m: number;
}

/** One row in the assignment history ledger. */
export interface DeviceAssignmentHistoryEntry {
    id: number;
    company_id: number | null;
    branch_id: number | null;
    assigned_at: string | null;
    unassigned_at: string | null;
    assigned_by_admin_id: number | null;
    unassign_reason: string | null;
    company: { id: number; name: string } | null;
    branch: { id: number; name: string } | null;
}

/** Shape of a device as returned by the list endpoint. */
export interface DeviceListItem {
    id: number;
    uuid: string;
    serial_number: string;
    kiosk_id: string | null;
    name: string | null;
    label: string | null;
    model: string | null;
    device_type: DeviceType | null;
    status: DeviceStatus | null;
    company_id: number | null;
    branch_id: number | null;
    // Bank-issued terminal identifier (Sprint 1.4 follow-up).
    // Globally unique. Persisted as a string because banks issue
    // mixed numeric / alphanumeric formats.
    terminal_id: string | null;
    // Commission profile FK + nested summary when preloaded. The
    // profile drives the donation-split calculation server-side.
    commission_profile_id: number | null;
    commission_profile?: { id: number; name: string; is_active: boolean } | null;
    // Acquiring bank FK + nested summary when preloaded. Disambiguates
    // which bank's API the reconciler talks to for this terminal_id.
    bank_id: number | null;
    bank?: {
        id: number;
        name: string;
        short_name: string | null;
        swift_code: string | null;
        is_active: boolean;
    } | null;
    // Catalogue FKs — Sprint 1.4. Replaces the free-text model
    // string the resource used to expose. The nested `make` and
    // `model` summaries are present when the controller preloaded
    // the relations (which it does on list, show, register, etc.).
    make_id: number | null;
    model_id: number | null;
    make?: { id: number; name: string } | null;
    model?: { id: number; name: string } | null;
    company?: DeviceCompanySummary;
    branch?: DeviceBranchSummary;
    last_seen_at: string | null;
    last_ip: string | null;
    last_lat: number | null;
    last_lng: number | null;
    last_battery: number | null;
    app_version: string | null;
    firmware_version: string | null;
    assigned_at: string | null;
    registered_by_user_id: number | null;
    assigned_by_user_id: number | null;
    created_at: string | null;
    updated_at: string | null;
}

/** Detail-endpoint response carries the assignment_history array too. */
export interface DeviceDetail extends DeviceListItem {
    assignment_history?: DeviceAssignmentHistoryEntry[];
}

/** Paginated list response — same envelope shape Laravel produces by default. */
export interface PaginatedDevices {
    data: DeviceListItem[];
    meta: PaginationMeta;
    links: PaginationLinks;
}

/** Payload accepted by POST /admin/api/v1/devices. */
export interface RegisterDevicePayload {
    serial_number: string;
    kiosk_id: string;
    device_type: DeviceType;
    // Catalogue FKs — both required. The back-end enforces that
    // model_id belongs to the chosen make_id.
    make_id: number;
    model_id: number;
    // Bank-issued terminal identifier + the commission profile +
    // acquiring bank this device is bound to. All three required at
    // registration so the reconciler has everything it needs to
    // route a payment back from day one.
    terminal_id: string;
    commission_profile_id: number;
    bank_id: number;
    name?: string | null;
    label?: string | null;
    app_version?: string | null;
    firmware_version?: string | null;
    metadata?: Record<string, unknown> | null;
}

/** Payload accepted by POST /admin/api/v1/devices/{uuid}/assign. */
export interface AssignDevicePayload {
    company_id: number;
    branch_id: number;
    geofence_radius_m?: number;
}

/** Payload accepted by POST /admin/api/v1/devices/{uuid}/unassign. */
export interface UnassignDevicePayload {
    reason?: string;
}

/**
 * Query string parameters supported by GET /admin/api/v1/devices.
 * Optional values are stripped before the request goes out.
 */
export interface DevicesQuery {
    page?: number;
    per_page?: number;
    device_type?: DeviceType;
    status?: DeviceStatus;
    company_id?: number;
    branch_id?: number;
    unassigned?: boolean;
    search?: string;
    [key: string]: string | number | boolean | null | undefined;
}

/** GET /admin/api/v1/devices — fetch the paginated fleet list. */
export function listDevices(query: DevicesQuery = {}): Promise<PaginatedDevices> {
    return apiGet<PaginatedDevices>('/admin/api/v1/devices', { query });
}

/** GET /admin/api/v1/devices/{uuid} — fetch a device + assignment history. */
export function getDevice(uuid: string): Promise<{ data: DeviceDetail }> {
    return apiGet<{ data: DeviceDetail }>(`/admin/api/v1/devices/${uuid}`);
}

/** POST /admin/api/v1/devices — register a brand new device. */
export function registerDevice(payload: RegisterDevicePayload): Promise<{ data: DeviceDetail }> {
    return apiPost<{ data: DeviceDetail }>(
        '/admin/api/v1/devices',
        payload as unknown as JsonValue,
    );
}

/** POST /admin/api/v1/devices/{uuid}/assign — bind to (company, branch). */
export function assignDevice(uuid: string, payload: AssignDevicePayload): Promise<{ data: DeviceDetail }> {
    return apiPost<{ data: DeviceDetail }>(
        `/admin/api/v1/devices/${uuid}/assign`,
        payload as unknown as JsonValue,
    );
}

/** POST /admin/api/v1/devices/{uuid}/unassign — release from current branch. */
export function unassignDevice(uuid: string, payload: UnassignDevicePayload = {}): Promise<{ data: DeviceDetail }> {
    return apiPost<{ data: DeviceDetail }>(
        `/admin/api/v1/devices/${uuid}/unassign`,
        payload as unknown as JsonValue,
    );
}

/**
 * POST /admin/api/v1/devices/{uuid}/decommission — close any open
 * assignment row, flip status to Blocked, soft-delete the row.
 * Server returns 204. Reason is optional, stamped on the closed
 * history row + the audit event for forensic context.
 */
export interface DecommissionDevicePayload {
    reason?: string;
}
export function decommissionDevice(uuid: string, payload: DecommissionDevicePayload = {}): Promise<void> {
    return apiPost<void>(
        `/admin/api/v1/devices/${uuid}/decommission`,
        payload as unknown as JsonValue,
    );
}

/**
 * Lane A — mint a one-shot activation code for an assigned
 * device. The Android cashier app exchanges this on pos_merchant
 * for a long-lived Sanctum personal-access token at first boot.
 *
 * The plaintext code is returned ONCE — DB only stores sha256.
 * UI must show + copy-button it immediately and never log it.
 *
 * 409 when the device isn't assigned (no branch / no company)
 * or is decommissioned — those preconditions are surfaced as a
 * state conflict, not a validation error.
 */
export interface IssueActivationTokenResponse {
    activation_code: string;
    expires_in_minutes: number;
}
export function issueDeviceActivationToken(
    uuid: string,
): Promise<IssueActivationTokenResponse> {
    return apiPost<IssueActivationTokenResponse>(
        `/admin/api/v1/devices/${uuid}/activation-token`,
    );
}
