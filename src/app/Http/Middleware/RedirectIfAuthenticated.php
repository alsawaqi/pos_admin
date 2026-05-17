<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hard guest gate for the SPA login shell.
 *
 * We do not rely on Laravel's default 'guest' alias here — defining our own
 * middleware and wiring it explicitly in routes/web.php guarantees the
 * redirect cannot be bypassed by stale config cache, a misconfigured
 * Middleware::redirectUsersTo closure, or a future change to the default
 * alias map. Belt-and-braces, intentionally.
 */
class RedirectIfAuthenticated
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $redirectTo = '/admin'): Response
    {
        if (Auth::guard('web')->check()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Already authenticated.',
                    'redirect' => $redirectTo,
                ], 409);
            }

            return redirect($redirectTo);
        }

        return $next($request);
    }
}
