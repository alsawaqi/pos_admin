<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
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
            // Only bounce REAL platform admins away from /login. A
            // merchant who somehow has a leftover session needs to
            // see the login form so they can re-authenticate as a
            // real admin (or as themselves into pos_merchant). We
            // also drop their session here proactively so the next
            // request from this client is unambiguously anonymous.
            /** @var User|null $user */
            $user = Auth::guard('web')->user();
            if ($user !== null && ! $user->isPlatformAdmin()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $next($request);
            }

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
