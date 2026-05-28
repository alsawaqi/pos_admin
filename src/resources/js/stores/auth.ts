import { reactive } from 'vue';
import { ApiError, apiGet } from '@/lib/api';
import { setSentryUser } from '@/lib/sentry';

/**
 * SPA auth state cache.
 *
 * The login + logout boundaries are now traditional form POSTs handled by
 * the browser — those flows do NOT live here anymore. This module only
 * exposes "who is currently signed in" so the in-SPA UI (sidebar,
 * permission checks, etc.) can react. The source of truth is the server's
 * /auth/user endpoint, fetched on demand by ensureAuthLoaded().
 *
 * Idle timeout is enforced server-side by EnsurePosAdminSessionIsFresh on
 * the /admin route group. We deliberately do NOT run a client-side
 * setTimeout that auto-submits /auth/logout: that timer fired
 * unconditionally after 30 minutes of wall-clock time regardless of
 * whether the user was actively interacting, which produced surprise
 * logouts mid-task. The next request after a truly idle session will be
 * bounced to /login by the server middleware, which is the correct moment
 * for the user to find out they need to sign in again.
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
    // Sprint 3 — stamp the Sentry scope so client-side errors
    // surface with the actor attached. No-op if Sentry isn't
    // initialised (no DSN).
    setSentryUser(response.user ? { id: response.user.id, email: response.user.email ?? undefined } : null);
}

function clearAuthState(): void {
    authState.user = null;
    authState.session = null;
    authState.loaded = true;
    // Clear Sentry scope so a subsequent login error from a
    // different actor doesn't get attributed to the previous one.
    setSentryUser(null);
}
