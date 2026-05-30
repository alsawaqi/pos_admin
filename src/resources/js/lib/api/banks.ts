import { apiGet } from '@/lib/api';

/**
 * Read-only client for the acquiring-banks catalogue
 * (GET /admin/api/v1/banks, BanksController). Banks are owned by the charity
 * application that shares this database; the admin only picks from the list.
 * Used by the Register Device page and the device-assign flow to bind a
 * device's terminal to a bank. Returns active banks by default.
 */
export interface Bank {
    id: number;
    name: string;
    short_name: string | null;
    swift_code: string | null;
    is_active: boolean;
}

/** Backwards-compatible alias. */
export type BankOption = Bank;

export interface BanksResponse {
    data: Bank[];
}

/** GET /admin/api/v1/banks — active banks for the bank dropdowns. */
export function listBanks(): Promise<BanksResponse> {
    return apiGet<BanksResponse>('/admin/api/v1/banks');
}
