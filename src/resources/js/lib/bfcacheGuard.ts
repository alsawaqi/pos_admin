/**
 * Disable the browser back/forward cache (bfcache) for the admin SPA.
 *
 * Why: when the browser restores a page from bfcache it brings the entire
 * JavaScript state with it — including a stale `authState`. A logged-out
 * user can press the back button and see the dashboard that was painted
 * before logout, because no server request was made and the cached HTML
 * still shows the previous user's view. Any subsequent client-side
 * re-check has at minimum a flash of stale content; at worst it never
 * fires fast enough to matter.
 *
 * How: registering an `unload` listener is the de-facto opt-out signal
 * recognised by Chromium and Firefox — its mere existence makes the
 * document ineligible for bfcache. The listener body does nothing.
 *
 * Belt-and-braces: Safari and some webviews still bfcache documents that
 * have unload listeners. For those, the `pageshow` event fires with
 * `persisted=true` when a cached page is restored. We respond with a
 * hard `window.location.reload()` so the server's auth middleware gets
 * to make the routing decision instead of stale client state. This is
 * synchronous — no XHR roundtrip, no visible flash window.
 *
 * Tradeoff: back/forward navigation now costs one HTTP round-trip
 * instead of a memory-restore. For an admin tool that is the correct
 * tradeoff — security and consistency over a 50ms perf win.
 */
export function installBfcacheGuard(): void {
    if (typeof window === 'undefined') {
        return;
    }

    // Opt out of bfcache. Empty handler — only the listener's presence matters.
    window.addEventListener('unload', () => {
        // intentionally empty
    });

    window.addEventListener('pageshow', (event: PageTransitionEvent) => {
        if (event.persisted) {
            window.location.reload();
        }
    });
}
