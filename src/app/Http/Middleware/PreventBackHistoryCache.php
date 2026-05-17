<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forbids the browser from caching authenticated SPA shells so the back
 * button after logout cannot resurrect the previously rendered admin page.
 *
 * `Cache-Control: no-store` is the most reliable signal to also disqualify
 * the document from the browser's back-forward cache (bfcache). Pair with
 * the client-side `pageshow` listener that forces an auth re-check when a
 * page is restored from bfcache.
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

        return $response;
    }
}
