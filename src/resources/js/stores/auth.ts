import { reactive } from 'vue';
import { ApiError, apiGet, apiPost } from '@/lib/api';

export interface AuthUser {
    id: number | string;
    name: string | null;
    email: string | null;
    user_type: string | null;
    status: string | null;
    roles?: string[];
    permissions?: string[];
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
    [key: string]: string | boolean;
}

export { ApiError };

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
        const response = await apiGet<AuthResponse>('/auth/user');
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
        const response = await apiPost<AuthResponse>('/auth/login', payload);
        applyAuthResponse(response);

        return response;
    } finally {
        authState.loaded = true;
        authState.loading = false;
    }
}

export async function logout(options: { redirectTo?: string } = {}): Promise<void> {
    try {
        await apiPost<void>('/auth/logout');
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

    if (error.isValidationError()) {
        return error.firstValidationMessage() ?? 'Please check your email and password.';
    }

    return 'We could not sign you in. Please try again.';
}

export function hasPermission(permission: string): boolean {
    return authState.user?.permissions?.includes(permission) ?? false;
}

export function hasRole(role: string): boolean {
    return authState.user?.roles?.includes(role) ?? false;
}

export function hasAnyRole(roles: readonly string[]): boolean {
    if (roles.length === 0) {
        return true;
    }

    return roles.some((role) => hasRole(role));
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
