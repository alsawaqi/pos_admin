/**
 * Shared HTTP client for the admin SPA. Centralises CSRF + JSON handling,
 * standardises error shapes, and keeps stores thin.
 *
 * Add request interceptors / token rotation here rather than duplicating
 * fetch calls across stores.
 */

export type JsonValue =
    | null
    | boolean
    | number
    | string
    | JsonValue[]
    | { [key: string]: JsonValue };

export interface ApiRequestOptions extends Omit<RequestInit, 'body' | 'headers'> {
    body?: JsonValue;
    headers?: Record<string, string>;
    query?: Record<string, string | number | boolean | null | undefined>;
}

export interface ValidationErrorPayload {
    message?: string;
    errors: Record<string, string[]>;
}

export class ApiError extends Error {
    public constructor(
        public readonly status: number,
        public readonly payload: unknown,
        message?: string,
    ) {
        super(message ?? `Request failed with status ${status}`);
        this.name = 'ApiError';
    }

    public isValidationError(): this is ApiError & { payload: ValidationErrorPayload } {
        return this.status === 422 && hasValidationErrors(this.payload);
    }

    public firstValidationMessage(): string | null {
        if (!this.isValidationError()) {
            return null;
        }

        for (const messages of Object.values(this.payload.errors)) {
            const [message] = messages;

            if (message) {
                return message;
            }
        }

        return null;
    }
}

export async function apiRequest<T>(url: string, options: ApiRequestOptions = {}): Promise<T> {
    const { body, headers = {}, query, ...rest } = options;
    const finalUrl = appendQuery(url, query);

    const response = await fetch(finalUrl, {
        credentials: 'same-origin',
        ...rest,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
            ...headers,
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    if (response.status === 204) {
        return undefined as T;
    }

    const payload: unknown = await response.json().catch(() => null);

    if (!response.ok) {
        throw new ApiError(response.status, payload, messageFrom(payload));
    }

    return payload as T;
}

export function apiGet<T>(url: string, options: Omit<ApiRequestOptions, 'body' | 'method'> = {}): Promise<T> {
    return apiRequest<T>(url, { ...options, method: 'GET' });
}

export function apiPost<T>(url: string, body?: JsonValue, options: Omit<ApiRequestOptions, 'body' | 'method'> = {}): Promise<T> {
    return apiRequest<T>(url, { ...options, method: 'POST', body });
}

export function apiPatch<T>(url: string, body?: JsonValue, options: Omit<ApiRequestOptions, 'body' | 'method'> = {}): Promise<T> {
    return apiRequest<T>(url, { ...options, method: 'PATCH', body });
}

export function apiDelete<T>(url: string, options: Omit<ApiRequestOptions, 'body' | 'method'> = {}): Promise<T> {
    return apiRequest<T>(url, { ...options, method: 'DELETE' });
}

function csrfToken(): string {
    const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');

    return meta?.content ?? '';
}

function appendQuery(
    url: string,
    query?: Record<string, string | number | boolean | null | undefined>,
): string {
    if (!query) {
        return url;
    }

    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(query)) {
        if (value === null || value === undefined) {
            continue;
        }

        params.append(key, String(value));
    }

    const queryString = params.toString();

    if (queryString === '') {
        return url;
    }

    return url + (url.includes('?') ? '&' : '?') + queryString;
}

function hasValidationErrors(payload: unknown): payload is ValidationErrorPayload {
    return typeof payload === 'object'
        && payload !== null
        && 'errors' in payload
        && typeof (payload as { errors: unknown }).errors === 'object';
}

function messageFrom(payload: unknown): string | undefined {
    if (typeof payload !== 'object' || payload === null) {
        return undefined;
    }

    const message = (payload as { message?: unknown }).message;

    return typeof message === 'string' ? message : undefined;
}
