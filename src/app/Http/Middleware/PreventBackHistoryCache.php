<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Globally forbids the browser from reusing any response.
 *
 *   - Cache-Control: no-store — the page must never be saved to disk
 *     or memory cache, and is also disqualified from the back/forward
 *     cache by every well-behaved browser.
 *   - Vary: Cookie — keys cached entries by the session cookie, so a
 *     logged-out browser cannot reuse a logged-in cached response (and
 *     vice versa) even if a proxy or browser ignores no-store.
 *   - Pragma: no-cache and Expires: 0 are belt-and-braces for old
 *     HTTP/1.0 intermediaries.
 *
 * The middleware also actively expires legacy session cookies that
 * might still be in users' browsers from earlier iterations of this
 * project, so we never serve a request authenticated against a stale
 * cookie that no longer matches the configured session.cookie name.
 */
class PreventBackHistoryCache
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        $this->addVaryCookie($response);

        foreach ($this->legacySessionCookies() as $cookieName) {
            $response->headers->setCookie(Cookie::forget($cookieName));
        }

        return $response;
    }

    /**
     * Append `Cookie` to the Vary header without clobbering any other
     * Vary value previously set by the framework or earlier middleware.
     */
    private function addVaryCookie(Response $response): void
    {
        $existing = (string) $response->headers->get('Vary', '');
        $values = array_filter(array_map('trim', explode(',', $existing)));

        if (in_array('Cookie', $values, true)) {
            return;
        }

        $values[] = 'Cookie';
        $response->headers->set('Vary', implode(', ', $values));
    }

    /**
     * @return list<string>
     */
    private function legacySessionCookies(): array
    {
        $currentSessionCookie = (string) config('session.cookie');

        return array_values(array_filter([
            'laravel_session',
            'mithqal-pos-admin-session',
            'pos_admin_session',
        ], static fn (string $cookieName): bool => $cookieName !== $currentSessionCookie));
    }
}
