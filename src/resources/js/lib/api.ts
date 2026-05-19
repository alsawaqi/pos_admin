/**
 * Shared HTTP client for the admin SPA. Centralises CSRF + JSON handling,
 * standardises error shapes, and keeps stores thin.
 *
 * Three production behaviours worth knowing:
 *
 *  - Auth interceptor: any response that turns out to be unauthenticated
 *    (HTTP 401 from the server, or HTTP 419 after a CSRF retry has already
 *    been attempted) triggers a hard navigation to /login. The current URL
 *    is preserved as ?redirect=<intended> so the user lands on the page
 *    they were looking at after re-authenticating. Individual stores and
 *    components therefore do NOT need to handle "what if my session
 *    expired" themselves — every API call is implicitly guarded.
 *
 *  - CSRF auto-retry: when a request fails with HTTP 419 (token mismatch)
 *    we fetch a fresh token from /auth/csrf, update the meta tag, and
 *    replay the request exactly once. This eliminates the first-attempt
 *    failure mode where the meta tag was rendered against a session that
 *    has since been rotated.
 *
 *  - The /auth/user endpoint is the ONE legitimate consumer of a 401 — it
 *    is how the SPA asks the server "is anyone signed in?". Receiving 401
 *    there is normal state, not an interrupt. The interceptor skips it.
 */

const CSRF_ENDPOINT = '/auth/csrf';
const AUTH_PROBE_ENDPOINT = '/auth/user';
const LOGIN_PATH = '/login';

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
    /**
     * Opt this single call out of the global 401/419 redirect-to-login
     * interceptor. Used by the auth store when it deliberately probes
     * /auth/user to find out the current sign-in state.
     */
    skipAuthInterceptor?: boolean;
    /**
     * Internal flag: set when retrying a request after a 419. Prevents
     * infinite recursion if the refresh itself returns 419 for whatever
     * reason.
     */
    _csrfRetried?: boolean;
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
    const { body, headers = {}, query, _csrfRetried, skipAuthInterceptor, ...rest } = options;
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

    // Soft retry on CSRF mismatch: fetch a fresh token then replay the request
    // exactly once. This covers the common case where the meta tag is stale
    // (session rotated between page render and submit).
    if (response.status === 419 && !_csrfRetried && url !== CSRF_ENDPOINT) {
        await refreshCsrf();

        return apiRequest<T>(url, { ...options, _csrfRetried: true });
    }

    const payload: unknown = await response.json().catch(() => null);

    if (!response.ok) {
        if (!skipAuthInterceptor && isAuthFailure(response.status, url, _csrfRetried)) {
            redirectToLogin();
        }

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

/**
 * Force a fresh CSRF token from the server and update the meta tag in place.
 *
 * Call this:
 *   - Immediately before a native auth form POST, so the form never carries
 *     a stale token from a session that was rotated by an earlier guest hit.
 *   - Automatically by apiRequest() after a 419 to recover before retrying.
 */
export async function refreshCsrf(): Promise<string> {
    const response = await fetch(CSRF_ENDPOINT, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        return csrfToken();
    }

    const payload = (await response.json().catch(() => null)) as { csrf_token?: string } | null;
    const token = payload?.csrf_token ?? '';

    if (token !== '') {
        setMetaCsrfToken(token);
    }

    return token;
}

/**
 * Is this response telling us "your session is no longer valid"?
 *
 *   - 401 from any endpoint other than the deliberate /auth/user probe.
 *   - 419 ONLY after we have already burned our one CSRF retry — if a
 *     fresh-token replay also failed, the underlying problem is that the
 *     session itself is gone, not a stale token, so route it through the
 *     same redirect-to-login path.
 */
function isAuthFailure(status: number, url: string, csrfRetried: boolean | undefined): boolean {
    if (url === AUTH_PROBE_ENDPOINT) {
        return false;
    }

    if (status === 401) {
        return true;
    }

    if (status === 419 && csrfRetried === true) {
        return true;
    }

    return false;
}

/**
 * Hard-navigate to /login, preserving the current URL so the user can be
 * sent back there after re-authenticating. Guards against redirect loops
 * (no-op if we are already on /login) and against running under SSR.
 *
 * window.location.replace() is intentional: drops every in-memory store +
 * pending request from the current SPA instance so a stale authState
 * cannot bleed into the relog flow.
 */
function redirectToLogin(): void {
    if (typeof window === 'undefined') {
        return;
    }

    if (window.location.pathname === LOGIN_PATH) {
        return;
    }

    const intended = encodeURIComponent(window.location.pathname + window.location.search);

    window.location.replace(`${LOGIN_PATH}?expired=1&redirect=${intended}`);
}

function csrfToken(): string {
    const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');

    return meta?.content ?? '';
}

function setMetaCsrfToken(token: string): void {
    let meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');

    if (!meta) {
        meta = document.createElement('meta');
        meta.setAttribute('name', 'csrf-token');
        document.head.appendChild(meta);
    }

    meta.setAttribute('content', token);
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
