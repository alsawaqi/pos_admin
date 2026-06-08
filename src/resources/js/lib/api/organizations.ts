/**
 * Read-only TypeScript client for the shared organizations catalogue (lives in
 * charity_db, owned by the charity app — POS just reads from it).
 *
 * Used by the Register Device page to populate the "Organization" dropdown (the
 * beneficiary org a device's card round-up donations go to).
 */

import { apiGet } from '@/lib/api';

export interface Organization {
    id: number;
    name: string;
    is_active: boolean;
}

export interface OrganizationsQuery {
    search?: string;
    include_inactive?: boolean;
    [key: string]: string | number | boolean | null | undefined;
}

/** GET /admin/api/v1/organizations — defaults to active rows only. */
export function listOrganizations(
    query: OrganizationsQuery = {},
): Promise<{ data: Organization[] }> {
    return apiGet<{ data: Organization[] }>(
        '/admin/api/v1/organizations',
        { query },
    );
}
