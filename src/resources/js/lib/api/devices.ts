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

import { apiGet, apiPost, apiPatch, type JsonValue } from '@/lib/api';
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
export interface ScalefusionStatus {
    id: number | null;
    name: string | null;
    battery_status: number | string | null;
    battery_charging: boolean | null;
    connection_state: string | null;
    connection_status: string | null;
    device_status: string | null;
    locked: boolean | null;
    last_connected_at: string | null;
    last_seen_on: string | null;
    ip_address: string | null;
    public_ip: string | null;
    location: {
        lat: number | string | null;
        lng: number | string | null;
        address: string | null;
        date_time: string | null;
    };
}

export interface DeviceListItem {
    id: number;
    uuid: string;
    serial_number: string;
    kiosk_id: string | null;
    name: string | null;
    label: string | null;
    device_type: DeviceType | null;
    status: DeviceStatus | null;
    company_id: number | null;
    branch_id: number | null;
    // Bank-issued terminal identifier (Sprint 1.4 follow-up).
    // Globally unique. Persisted as a string because banks issue
    // mixed numeric / alphanumeric formats.
    terminal_id: string | null;
    // Bank-issued Mosambee Soft-POS login PIN, captured at assign
    // time beside terminal_id. Null ⇒ the device falls back to the
    // vendor default PIN.
    terminal_pin: string | null;
    // Commission profile FK + nested summary when preloaded. The
    // profile drives the donation-split calculation server-side.
    commission_profile_id: number | null;
    commission_profile?: { id: number; name: string; is_active: boolean } | null;
    // Beneficiary organization FK + nested summary when preloaded. The device's
    // card round-up donations go to this org.
    organization_id: number | null;
    organization?: { id: number; name: string; is_active: boolean } | null;
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
    // Live scalefusion (MDM) status, present only when listDevices is
    // called with with_scalefusion. Joined by kiosk_id; null when the
    // device isn't enrolled or scalefusion is unreachable.
    scalefusion?: ScalefusionStatus | null;
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
    // Commission profile (donation-split rule) is the ONLY acquiring
    // detail captured at registration.
    commission_profile_id: number;
    // Beneficiary organization (the device's round-up donations go here).
    // Required at registration, like commission_profile_id.
    organization_id: number;
    // The acquiring bank + bank-issued terminal_id are NOT captured at
    // registration — they belong to the merchant's bank account and are
    // set when the device is ASSIGNED to a merchant's branch (see
    // AssignDevicePayload), so they are deliberately omitted here.
    name?: string | null;
    label?: string | null;
    app_version?: string | null;
    firmware_version?: string | null;
    metadata?: Record<string, unknown> | null;
}

/**
 * Payload accepted by PATCH /admin/api/v1/devices/{uuid} — partial edit of a
 * registered device's identity + catalogue + commission/organization bindings.
 * Every field is optional; only the ones present are changed. Assignment +
 * terminal/bank + status are NOT editable here (their own workflows handle them).
 */
export interface UpdateDevicePayload {
    serial_number?: string;
    kiosk_id?: string;
    name?: string | null;
    label?: string | null;
    make_id?: number;
    model_id?: number;
    device_type?: DeviceType;
    commission_profile_id?: number;
    organization_id?: number;
}

/** Payload accepted by POST /admin/api/v1/devices/{uuid}/assign. */
export interface AssignDevicePayload {
    company_id: number;
    branch_id: number;
    // Soft-POS terminal binding, captured at assign time (the terminal is
    // issued against the merchant's bank account). terminal_id is unique per
    // bank, enforced server-side. Typed OPTIONAL here so the legacy standalone
    // device-detail assign call (company/branch only) still type-checks; the
    // merchant-view AssignDeviceModal always sends both and the backend
    // requires them on that path.
    bank_id?: number;
    terminal_id?: string;
    // Optional Mosambee login PIN issued by the bank with the
    // terminal. null / omitted ⇒ stored as NULL server-side and the
    // device uses the vendor default PIN.
    terminal_pin?: string | null;
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
    with_scalefusion?: boolean;
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

/** PATCH /admin/api/v1/devices/{uuid} — edit a registered device. */
export function updateDevice(uuid: string, payload: UpdateDevicePayload): Promise<{ data: DeviceDetail }> {
    return apiPatch<{ data: DeviceDetail }>(
        `/admin/api/v1/devices/${uuid}`,
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


// --- Scalefusion (MDM) live device surface --------------------------
// The detail endpoint relays scalefusion's raw v3 device payload, so
// every field is optional + an index signature keeps the rest
// accessible. The panel unwraps an optional `device` envelope.

export interface ScalefusionStorageInfo {
    total_internal_storage?: number | null;
    total_internal_storage_avbl?: number | null;
    [key: string]: unknown;
}

export interface ScalefusionDeviceDetail {
    device?: ScalefusionDeviceDetail | null;
    name?: string | null;
    model?: string | null;
    app_version_name?: string | null;
    os_version?: string | null;
    connection_status?: string | null;
    device_status?: string | null;
    battery_status?: number | string | null;
    battery_charging?: boolean | null;
    battery_health?: string | null;
    locked?: boolean | null;
    ip_address?: string | null;
    public_ip?: string | null;
    serial_no?: string | null;
    build_serial_no?: string | null;
    imei_no?: string | null;
    phone_no?: string | null;
    sim_network?: string | null;
    sim1_network_type?: string | null;
    sim_signal_strength?: number | null;
    connected_wifi_ssid?: string | null;
    avbl_wifi_ssids?: string[] | null;
    last_seen_on?: string | null;
    total_ram_size?: number | null;
    ram_usage?: number | null;
    cpu_usage?: number | null;
    cpu_temp_in_celsius?: number | null;
    battery_temp_in_celsius?: number | null;
    screen_temp_in_celsius?: number | null;
    storage_info?: ScalefusionStorageInfo | null;
    location?: {
        lat?: number | string | null;
        lng?: number | string | null;
        address?: string | null;
        created_at?: string | null;
        [key: string]: unknown;
    } | null;
    device_group?: { name?: string | null } | null;
    device_profile?: { name?: string | null } | null;
    management_details?: {
        enrollment_mode?: string | null;
        enrollment_status?: string | null;
        management_state?: string | null;
    } | null;
    enrollment_status?: string | null;
    management_state?: string | null;
    license?: { expire_date?: string | null } | null;
    device_attestation_status?: string | null;
    [key: string]: unknown;
}

export interface ScalefusionLocationPoint {
    device_id: number;
    location_id: number | null;
    address: string | null;
    latitude: number | null;
    longitude: number | null;
    accuracy: number | null;
    date_time: number | null;
    created_at_tz: string | null;
}

/** Every control action relays scalefusion's outcome. */
export interface ScalefusionActionResult {
    ok: boolean;
    data: unknown;
}

export type ScalefusionActionType =
    | 'screen_lock'
    | 'shutdown'
    | 'reboot'
    | 'mark_as_lost'
    | 'mark_as_found'
    | 'factory_reset'
    | 'delete_device'
    | 'buzz_device'
    | 'rotate_filevault_key';

export interface DeviceActionPayload {
    action_type: ScalefusionActionType;
    lost_mode_message?: string;
    lost_mode_footnote?: string;
    lost_mode_phone?: string;
    wipe_sd_card?: boolean;
}

export interface BroadcastMessagePayload {
    sender_name: string;
    message_body: string;
    keep_ringing?: boolean;
    show_as_dialog?: boolean;
}

/** GET /admin/api/v1/devices/{uuid}/scalefusion — live MDM detail. */
export function getDeviceScalefusion(uuid: string): Promise<{ data: ScalefusionDeviceDetail | null }> {
    return apiGet<{ data: ScalefusionDeviceDetail | null }>(`/admin/api/v1/devices/${uuid}/scalefusion`);
}

/** GET .../scalefusion/locations?date= — daily GPS route, oldest-first. */
export function getDeviceScalefusionLocations(
    uuid: string,
    date: string,
): Promise<{ data: ScalefusionLocationPoint[]; date: string }> {
    return apiGet<{ data: ScalefusionLocationPoint[]; date: string }>(
        `/admin/api/v1/devices/${uuid}/scalefusion/locations`,
        { query: { date } },
    );
}

export function rebootDevice(uuid: string): Promise<ScalefusionActionResult> {
    return apiPost<ScalefusionActionResult>(`/admin/api/v1/devices/${uuid}/scalefusion/reboot`);
}

export function alarmDevice(uuid: string): Promise<ScalefusionActionResult> {
    return apiPost<ScalefusionActionResult>(`/admin/api/v1/devices/${uuid}/scalefusion/alarm`);
}

export function lockDevice(uuid: string): Promise<ScalefusionActionResult> {
    return apiPost<ScalefusionActionResult>(`/admin/api/v1/devices/${uuid}/scalefusion/lock`);
}

export function unlockDevice(uuid: string): Promise<ScalefusionActionResult> {
    return apiPost<ScalefusionActionResult>(`/admin/api/v1/devices/${uuid}/scalefusion/unlock`);
}

export function clearDeviceAppData(uuid: string): Promise<ScalefusionActionResult> {
    return apiPost<ScalefusionActionResult>(`/admin/api/v1/devices/${uuid}/scalefusion/clear-app-data`);
}

export function runDeviceAction(uuid: string, payload: DeviceActionPayload): Promise<ScalefusionActionResult> {
    return apiPost<ScalefusionActionResult>(
        `/admin/api/v1/devices/${uuid}/scalefusion/action`,
        payload as unknown as JsonValue,
    );
}

export function broadcastDeviceMessage(uuid: string, payload: BroadcastMessagePayload): Promise<ScalefusionActionResult> {
    return apiPost<ScalefusionActionResult>(
        `/admin/api/v1/devices/${uuid}/scalefusion/broadcast-message`,
        payload as unknown as JsonValue,
    );
}
