<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hard gate for the admin SPA shell.
 *
 * Single, explicit job: refuse to invoke the next handler when the
 * request is not authenticated against the web guard. This is a
 * project-owned replacement for Laravel's generic Authenticate
 * middleware on /admin/* so the policy can never be silently softened
 * by a config/alias change upstream.
 *
 * Behaviour:
 *   - Guest XHR:    401 JSON ("Unauthenticated.")
 *   - Guest GET:    302 redirect to /login, preserving the requested
 *                   URL as ?redirect=<intended> via Laravel's
 *                   redirect()->guest() so the user lands on the page
 *                   they wanted after re-authenticating.
 *   - Authed:       passes through, decorated with the strongest
 *                   browser-cache-defeating headers we can send so a
 *                   subsequent logout cannot re-show this response
 *                   from disk/memory/bfcache.
 *
 * Why not just keep Laravel's Authenticate? It works, but it's
 * configured via the Middleware::redirectGuestsTo closure which lives
 * far from the route declaration. An app-owned middleware keeps the
 * contract obvious right next to /admin in routes/web.php and lets us
 * attach the cache-defeating headers in one place.
 */
class EnsureUserIsAuthenticated
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('web')->check()) {
            return $this->unauthenticatedResponse($request);
        }

        $response = $next($request);

        $this->forbidBrowserCaching($response);

        return $response;
    }

    private function unauthenticatedResponse(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return redirect()->guest(route('login'));
    }

    /**
     * Adds the response headers that prevent browsers from showing this
     * page after logout. `no-store` covers fresh navigation, `Vary:
     * Cookie` makes the browser treat responses for different session
     * cookies as different cache entries, and the Expires/Pragma pair
     * defeats older HTTP/1.0 intermediaries that ignore Cache-Control.
     */
    private function forbidBrowserCaching(Response $response): void
    {
        $existing = $response->headers->get('Cache-Control', '');

        // Don't downgrade a more aggressive directive set by something
        // earlier in the chain; only set ours if nothing strong is there.
        if (! str_contains($existing, 'no-store')) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private');
        }

        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        $vary = (string) $response->headers->get('Vary', '');
        $varyValues = array_filter(array_map('trim', explode(',', $vary)));

        if (! in_array('Cookie', $varyValues, true)) {
            $varyValues[] = 'Cookie';
            $response->headers->set('Vary', implode(', ', $varyValues));
        }
    }
}
