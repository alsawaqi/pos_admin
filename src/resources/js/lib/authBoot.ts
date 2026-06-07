/**
 * Synchronous, pre-mount sanity check for the SPA's initial auth state.
 *
 * The server stamps `window.__INITIAL_AUTH__` into the HTML for the page
 * the browser is currently loading:
 *   - /admin/* shells   -> { user, session }
 *   - /login shell      -> null
 *
 * If the page that ends up in front of the user disagrees with that
 * stamp — for example because the browser restored a bfcache entry that
 * Clear-Site-Data + the bfcacheGuard somehow missed — we hard-navigate
 * to the correct URL before Vue is even allowed to mount. The browser's
 * HTML5 history entry is REPLACED (not pushed) so the bad page does not
 * remain in the back stack.
 *
 * This is the third defensive layer behind:
 *   1. Server middleware (EnsureUserIsAuthenticated / RedirectIfAuthenticated).
 *   2. SpaController belt-check.
 *
 * It exists so that even a maximally hostile browser cache (proxy strips
 * Clear-Site-Data, no-store ignored, unload not honoured) cannot show
 * the admin shell to an anonymous viewer.
 */
export function enforceInitialAuthRouting(): void {
    if (typeof window === 'undefined') {
        return;
    }

    const initialAuth = window.__INITIAL_AUTH__ ?? null;
    const path = window.location.pathname;
    const onAdmin = path === '/admin' || path.startsWith('/admin/');
    const onLogin = path === '/login';

    if (onAdmin && (initialAuth === null || initialAuth.user === undefined || initialAuth.user === null)) {
        redirectHard('/login');

        return;
    }

    if (onLogin && initialAuth !== null && initialAuth.user) {
        redirectHard('/admin');
    }
}

function redirectHard(target: string): void {
    if (typeof document !== 'undefined' && document.documentElement) {
        document.documentElement.style.visibility = 'hidden';
    }

    window.location.replace(target);
}
