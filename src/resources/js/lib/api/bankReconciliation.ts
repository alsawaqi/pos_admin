import { ApiError, apiPost, type JsonValue } from '@/lib/api';

/**
 * Bank reconciliation API. Preview is a multipart upload (the bank settlement
 * sheet) so it bypasses the JSON wrapper with a raw fetch; commit is plain JSON.
 */

export interface ReconciliationSummary {
    statement_rows: number;
    statement_amount: number;
    payment_rows: number;
    matched_rows: number;
    matched_amount: number;
    missing_in_db_rows: number;
    amount_mismatch_rows: number;
    db_only_rows: number;
    invalid_rows: number;
}

export interface StatementRow {
    row_number: number;
    date: string | null;
    terminal_id: string | null;
    auth_code: string | null;
    gross_amount: number | null;
    card_no?: string | null;
    errors?: string[];
}

export interface PaymentRow {
    id: number;
    terminal_id: string | null;
    auth_code: string | null;
    amount: number;
    status: string;
    pending_reconciliation: boolean;
    captured_at: string | null;
    matchable: boolean;
}

export interface ReconciliationPreview {
    bank: { id: number; name: string };
    statement_date: string;
    detected_statement_date: string | null;
    parser: string;
    summary: ReconciliationSummary;
    matched: { statement: StatementRow; payment: PaymentRow }[];
    missing_in_db: { statement: StatementRow; reason: string }[];
    amount_mismatches: { statement: StatementRow; payment: PaymentRow; amount_difference: number }[];
    db_only: PaymentRow[];
    invalid_rows: StatementRow[];
}

export interface ReconciliationCommitResult {
    reconciled: number;
    payment_ids: number[];
}

/** POST /admin/api/v1/bank-reconciliation/preview — multipart upload. */
export async function previewReconciliation(bankId: number, statementDate: string, file: File): Promise<{ data: ReconciliationPreview }> {
    const form = new FormData();
    form.append('bank_id', String(bankId));
    form.append('statement_date', statementDate);
    form.append('file', file);

    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

    const response = await fetch('/admin/api/v1/bank-reconciliation/preview', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrf,
        },
        body: form,
    });

    const payload: unknown = await response.json().catch(() => null);

    if (!response.ok) {
        throw new ApiError(response.status, payload);
    }

    return payload as { data: ReconciliationPreview };
}

/** POST /admin/api/v1/bank-reconciliation/commit — mark matched payments reconciled. */
export function commitReconciliation(paymentIds: number[]): Promise<{ data: ReconciliationCommitResult }> {
    return apiPost<{ data: ReconciliationCommitResult }>(
        '/admin/api/v1/bank-reconciliation/commit',
        { payment_ids: paymentIds } as unknown as JsonValue,
    );
}
