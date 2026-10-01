<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * LAUNCH-P1 P1-15 (owner decision 2026-10-01): two-step login
 * (authenticator) is REQUIRED for every admin.
 *
 * A signed-in admin without a confirmed authenticator can only reach
 * what the setup needs:
 *   - the Account security page (/admin/security), where the setup card
 *     lives;
 *   - the setup endpoints (POST /auth/two-factor, /auth/two-factor/
 *     confirm), /auth/user, /auth/csrf and /auth/logout.
 * Every other JSON call answers 403 `two_factor_setup_required`; every
 * other page redirects to /admin/security. The check reads the user's
 * current state on each request, so it also applies to remember-me
 * sessions and to sessions that pre-date the rule.
 *
 * Runs in the web group after EnsureUserAccess (which loads the fresh
 * user row); a guest request passes straight through.
 */
final class EnsureAdminTwoFactorEnrolled
{
    /** Paths an un-enrolled admin may still use. */
    private const ALLOWED = [
        'admin/security',
        'auth/user',
        'auth/csrf',
        'auth/logout',
        'auth/login',
        'auth/two-factor',
        'auth/two-factor/confirm',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User
            || ! $user->isPlatformAdmin()
            || $user->hasConfirmedTwoFactor()
            || ! (bool) config('pos_admin_auth.two_factor.required', true)
            || $request->is(...self::ALLOWED)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('admin/api/*')) {
            return response()->json([
                'message' => 'Set up two-step login (authenticator app) before using the admin portal.',
                'code' => 'two_factor_setup_required',
            ], 403);
        }

        return redirect('/admin/security?setup=required');
    }
}
