/**
 * Disable the browser back/forward cache (bfcache) for the admin SPA.
 *
 * Why: when the browser restores a page from bfcache it brings the entire
 * JavaScript state with it — including a stale `authState`. A logged-out
 * user can press the back button and see the dashboard that was painted
 * before logout, because no server request was made and the cached HTML
 * still shows the previous user's view.
 *
 * Strategy:
 *
 *   1. `Cache-Control: no-store` on every response (stamped by
 *      PreventBackHistoryCache) is the modern opt-out signal. Every major
 *      browser excludes no-store responses from bfcache, so a Back press
 *      always issues a real navigation and the server middleware decides
 *      whether the user belongs on the requested URL.
 *
 *   2. `pagehide` / `pageshow` with `persisted=true` — belt-and-braces in
 *      case some browser (or proxy stripping headers) restores from
 *      bfcache anyway. We hide the page and trigger a hard reload so
 *      the stale state can never be observed.
 *
 * The legacy `unload` listener was removed: it duplicated the no-store
 * opt-out, globally disabled bfcache on browsers that still honoured
 * unload (worse Back-navigation perf), and an empty unload handler can
 * fire mid-form-submission in some browsers in ways that subtly
 * interfered with cookie write ordering on the logout/login boundary.
 */
export function installBfcacheGuard(): void {
    if (typeof window === 'undefined') {
        return;
    }

    window.addEventListener('pagehide', (event: PageTransitionEvent) => {
        if (event.persisted) {
            hideDocument();
        }
    });

    window.addEventListener('pageshow', (event: PageTransitionEvent) => {
        if (event.persisted) {
            // Hide synchronously before the reload kicks in. Otherwise
            // there's a visible flash of the restored (stale) page between
            // the moment the browser paints the bfcache contents and the
            // moment the reload navigates away.
            hideDocument();
            window.location.reload();
        }
    });
}

function hideDocument(): void {
    if (typeof document === 'undefined' || document.documentElement === null) {
        return;
    }

    document.documentElement.style.visibility = 'hidden';
}
