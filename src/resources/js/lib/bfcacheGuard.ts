import type { Router } from 'vue-router';
import { authState, ensureAuthLoaded, resetAuthBootPromise } from '@/stores/auth';

/**
 * Defends against the browser back/forward cache (bfcache) leaking
 * authenticated screens after logout, and vice versa.
 *
 * When a document is restored from bfcache the JS state is also restored
 * verbatim — so a stale `authState.user` would let a logged-out user view
 * the dashboard until something else triggered a fetch. We listen for the
 * `pageshow` event with `persisted=true`, invalidate the cached auth boot
 * promise, re-fetch the user, and redirect when the routing rules now
 * mismatch.
 */
export function installBfcacheGuard(router: Router): void {
    if (typeof window === 'undefined') {
        return;
    }

    window.addEventListener('pageshow', async (event: PageTransitionEvent) => {
        if (!event.persisted) {
            return;
        }

        resetAuthBootPromise();
        await ensureAuthLoaded();

        const current = router.currentRoute.value;

        if (current.meta.requiresAuth && !authState.user) {
            await router.replace({
                name: 'login',
                query: { redirect: current.fullPath },
            });

            return;
        }

        if (current.meta.guestOnly && authState.user) {
            await router.replace({ name: 'admin.dashboard' });
        }
    });
}
