/**
 * Typed client for the read-only `banks` listing.
 *
 * The MITHQAL admin app does not manage banks — those are owned by
 * the charity application that shares this database. We only consume
 * the list to populate the "Bank" dropdown on the Register Device
 * page and to render the chosen bank's name on the Device Show
 * page.
 *
 * Mirrors {@link lib/api/commissionProfiles.ts} — same pattern, same
 * guarantees.
 */

import { apiGet } from '@/lib/api';

/**
 * Mirrors the shape exposed by
 * {@link \App\Http\Resources\Admin\BankResource}. Keep both in sync.
 */
export interface Bank {
    id: number;
    name: string;
    /** Short / display name (e.g. "BM" for Bank Muscat). May be null. */
    short_name: string | null;
    /** SWIFT/BIC code — surfaced on the Device Show page for clarity. */
    swift_code: string | null;
    is_active: boolean;
}

export interface BanksResponse {
    data: Bank[];
}

export interface BanksQuery {
    /** Substring match on `name` or `short_name`. */
    search?: string;
    /** Set true to surface deactivated banks too (default is active-only). */
    include_inactive?: boolean;
    [key: string]: string | number | boolean | null | undefined;
}

/**
 * GET /admin/api/v1/banks — fetch the active bank list for the
 * Register Device dropdown. Returns the full flat list (no
 * pagination); the bank catalogue is small enough that paging would
 * be overhead.
 */
export function listBanks(query: BanksQuery = {}): Promise<BanksResponse> {
    return apiGet<BanksResponse>('/admin/api/v1/banks', { query });
}
