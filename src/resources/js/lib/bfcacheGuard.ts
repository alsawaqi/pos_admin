/**
 * Disable the browser back/forward cache (bfcache) for the admin SPA.
 *
 * Why: when the browser restores a page from bfcache it brings the entire
 * JavaScript state with it — including a stale `authState`. A logged-out
 * user can press the back button and see the dashboard that was painted
 * before logout, because no server request was made and the cached HTML
 * still shows the previous user's view.
 *
 * Strategy (layered — no single layer is enough on every browser):
 *
 *   1. `unload` listener — the de-facto opt-out signal in Chromium and
 *      Firefox. Listener body is intentionally empty.
 *   2. `pagehide` with `persisted=true` — the page is about to be frozen
 *      into bfcache. Hide the document so that if the browser DOES
 *      restore it later, the user never sees the stale content.
 *   3. `pageshow` with `persisted=true` — the page WAS restored from
 *      bfcache. Hide instantly (in case pagehide didn't fire), then
 *      hard-reload so the server's middleware gets to decide whether
 *      this user should be on this URL.
 *
 * `Clear-Site-Data: "cache"` on the logout response is the authoritative
 * mechanism that evicts the bfcache entries entirely. This guard is the
 * client-side belt for cases where Clear-Site-Data was not honoured (old
 * browsers, non-secure contexts, proxies stripping the header).
 */
export function installBfcacheGuard(): void {
    if (typeof window === 'undefined') {
        return;
    }

    // Opt out of bfcache. Empty handler — only the listener's presence matters.
    window.addEventListener('unload', () => {
        // intentionally empty
    });

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
