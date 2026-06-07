<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses to serve a JSON-only endpoint to a direct browser navigation.
 *
 * Endpoints used by the SPA's XHR client (e.g. /auth/user) must not leak
 * their payload when a curious user types the URL into the address bar.
 * We require the request to advertise an `Accept: application/json` header
 * or the standard `X-Requested-With: XMLHttpRequest` signal; otherwise we
 * return a generic 404 so the existence of the endpoint isn't even
 * confirmed.
 *
 * Note: this is a defence-in-depth measure, not a substitute for auth.
 * The endpoint must still be gated by the auth middleware.
 */
class RequireJsonRequest
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isJsonAware($request)) {
            abort(404);
        }

        return $next($request);
    }

    private function isJsonAware(Request $request): bool
    {
        if ($request->expectsJson()) {
            return true;
        }

        $xRequestedWith = (string) $request->headers->get('X-Requested-With', '');

        return strcasecmp($xRequestedWith, 'XMLHttpRequest') === 0;
    }
}
