/**
 * Read-only TypeScript client for the shared commission profiles
 * catalogue (lives in charity_db, owned by the charity app — POS
 * just reads from it).
 *
 * Used by the Register Device page to populate the "Commission
 * profile" dropdown.
 */

import { apiGet } from '@/lib/api';

export interface CommissionProfile {
    id: number;
    name: string;
    is_active: boolean;
}

export interface CommissionProfilesQuery {
    search?: string;
    include_inactive?: boolean;
    [key: string]: string | number | boolean | null | undefined;
}

/** GET /admin/api/v1/commission-profiles — defaults to active rows only. */
export function listCommissionProfiles(
    query: CommissionProfilesQuery = {},
): Promise<{ data: CommissionProfile[] }> {
    return apiGet<{ data: CommissionProfile[] }>(
        '/admin/api/v1/commission-profiles',
        { query },
    );
}
