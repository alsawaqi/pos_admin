import { reactive } from 'vue';
import { ApiError, apiGet, refreshCsrf } from '@/lib/api';

/**
 * SPA auth state cache.
 *
 * The login + logout boundaries are now traditional form POSTs handled by
 * the browser — those flows do NOT live here anymore. This module only
 * exposes "who is currently signed in" so the in-SPA UI (sidebar,
 * permission checks, etc.) can react. The source of truth is the server's
 * /auth/user endpoint, fetched on demand by ensureAuthLoaded().
 */

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

interface AuthResponse {
    user: AuthUser;
    session: AuthSession;
}

interface AuthState {
    user: AuthUser | null;
    session: AuthSession | null;
    loaded: boolean;
    loading: boolean;
}

export { ApiError };

declare global {
    interface Window {
        __INITIAL_AUTH__?: AuthResponse | null;
    }
}

const initialAuth = typeof window === 'undefined'
    ? null
    : window.__INITIAL_AUTH__ ?? null;

export const authState = reactive<AuthState>({
    user: initialAuth?.user ?? null,
    session: initialAuth?.session ?? null,
    loaded: initialAuth !== null,
    loading: false,
});

let bootPromise: Promise<void> | null = null;
let idleLogoutTimer: number | null = null;

export async function ensureAuthLoaded(): Promise<void> {
    bootPromise ??= fetchCurrentUser();

    return bootPromise;
}

export function resetAuthBootPromise(): void {
    bootPromise = null;
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

/**
 * Kicks an idle, non-remembered user back to /login by triggering a
 * native logout. We submit a synthetic form so the browser handles the
 * navigation just like a manual logout click.
 */
async function expireSession(): Promise<void> {
    if (typeof document === 'undefined') {
        return;
    }

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/auth/logout';

    const csrfMeta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
    const freshToken = await refreshCsrf().catch(() => csrfMeta?.content ?? '');
    const tokenField = document.createElement('input');
    tokenField.type = 'hidden';
    tokenField.name = '_token';
    tokenField.value = freshToken || csrfMeta?.content || '';
    form.appendChild(tokenField);

    document.body.appendChild(form);
    form.submit();
}

// Kept in sync with PlatformRole::SuperAdmin. Centralised so any FE caller
// that needs to short-circuit a permission/role check has one place to ask.
export const SUPER_ADMIN_ROLE = 'platform_super_admin';

export function isSuperAdmin(): boolean {
    return authState.user?.roles?.includes(SUPER_ADMIN_ROLE) ?? false;
}

export function hasPermission(permission: string): boolean {
    if (isSuperAdmin()) {
        return true;
    }

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
    scheduleIdleLogout(response.session);
}

function clearAuthState(): void {
    authState.user = null;
    authState.session = null;
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
        void expireSession();
    }, session.idle_timeout_seconds * 1000);
}

if (initialAuth !== null) {
    scheduleIdleLogout(initialAuth.session);
}
