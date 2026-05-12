import { reactive } from 'vue';

export interface AuthUser {
    id: number | string;
    name: string | null;
    email: string | null;
    user_type: string | null;
    status: string | null;
}

export interface AuthSession {
    remembered: boolean;
    idle_timeout_seconds: number;
    last_activity_at: number | null;
}

interface JwtToken {
    type: 'Bearer';
    access_token: string;
    expires_at: string;
}

interface AuthResponse {
    user: AuthUser;
    session: AuthSession;
    token?: JwtToken;
}

interface AuthState {
    user: AuthUser | null;
    session: AuthSession | null;
    jwt: JwtToken | null;
    loaded: boolean;
    loading: boolean;
}

interface LoginPayload {
    email: string;
    password: string;
    remember: boolean;
}

export class ApiError extends Error {
    public constructor(
        public readonly status: number,
        public readonly payload: unknown,
    ) {
        super('Request failed');
    }
}

export const authState = reactive<AuthState>({
    user: null,
    session: null,
    jwt: null,
    loaded: false,
    loading: false,
});

let bootPromise: Promise<void> | null = null;
let idleLogoutTimer: number | null = null;

export async function ensureAuthLoaded(): Promise<void> {
    bootPromise ??= fetchCurrentUser();

    return bootPromise;
}

export async function fetchCurrentUser(): Promise<void> {
    authState.loading = true;

    try {
        const response = await jsonRequest<AuthResponse>('/auth/user');
        applyAuthResponse(response);
    } catch (error) {
        if (error instanceof ApiError && [401, 419].includes(error.status)) {
            clearAuthState();

            return;
        }

        throw error;
    } finally {
        authState.loaded = true;
        authState.loading = false;
    }
}

export async function login(payload: LoginPayload): Promise<AuthResponse> {
    authState.loading = true;

    try {
        const response = await jsonRequest<AuthResponse>('/auth/login', {
            method: 'POST',
            body: JSON.stringify(payload),
        });

        applyAuthResponse(response);

        return response;
    } finally {
        authState.loaded = true;
        authState.loading = false;
    }
}

export async function logout(options: { redirectTo?: string } = {}): Promise<void> {
    try {
        await jsonRequest<void>('/auth/logout', {
            method: 'POST',
        });
    } catch (error) {
        if (! (error instanceof ApiError) || ! [401, 419].includes(error.status)) {
            throw error;
        }
    } finally {
        clearAuthState();

        if (options.redirectTo) {
            window.location.assign(options.redirectTo);
        }
    }
}

export function loginErrorMessage(error: unknown): string {
    if (! (error instanceof ApiError)) {
        return 'We could not sign you in. Please try again.';
    }

    if (error.status === 429) {
        return 'Too many login attempts. Please wait a minute and try again.';
    }

    if (error.status === 422 && hasValidationErrors(error.payload)) {
        return firstValidationError(error.payload) ?? 'Please check your email and password.';
    }

    return 'We could not sign you in. Please try again.';
}

function applyAuthResponse(response: AuthResponse): void {
    authState.user = response.user;
    authState.session = response.session;
    authState.jwt = response.token ?? null;
    scheduleIdleLogout(response.session);
}

function clearAuthState(): void {
    authState.user = null;
    authState.session = null;
    authState.jwt = null;
    authState.loaded = true;

    if (idleLogoutTimer) {
        window.clearTimeout(idleLogoutTimer);
        idleLogoutTimer = null;
    }
}

function scheduleIdleLogout(session: AuthSession): void {
    if (idleLogoutTimer) {
        window.clearTimeout(idleLogoutTimer);
        idleLogoutTimer = null;
    }

    if (session.remembered || session.idle_timeout_seconds <= 0) {
        return;
    }

    idleLogoutTimer = window.setTimeout(() => {
        void logout({ redirectTo: '/login?expired=1' });
    }, session.idle_timeout_seconds * 1000);
}

async function jsonRequest<T>(url: string, options: RequestInit = {}): Promise<T> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
            ...headersToObject(options.headers),
        },
    });

    if (response.status === 204) {
        return undefined as T;
    }

    const payload: unknown = await response.json().catch(() => null);

    if (!response.ok) {
        throw new ApiError(response.status, payload);
    }

    return payload as T;
}

function csrfToken(): string {
    const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');

    return meta?.content ?? '';
}

function headersToObject(headers: HeadersInit | undefined): Record<string, string> {
    if (! headers) {
        return {};
    }

    if (headers instanceof Headers) {
        return Object.fromEntries(headers.entries());
    }

    if (Array.isArray(headers)) {
        return Object.fromEntries(headers);
    }

    return headers;
}

function hasValidationErrors(payload: unknown): payload is { errors: Record<string, string[]> } {
    return typeof payload === 'object'
        && payload !== null
        && 'errors' in payload;
}

function firstValidationError(payload: { errors: Record<string, string[]> }): string | null {
    for (const messages of Object.values(payload.errors)) {
        const [message] = messages;

        if (message) {
            return message;
        }
    }

    return null;
}
