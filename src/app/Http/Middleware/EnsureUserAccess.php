<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class EnsureUserAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User) {
            $fresh = User::query()->find($user->id);
            if ($fresh !== null && Auth::guard('web')->viaRemember()) {
                $request->session()->put('pos.auth_version', (int) $fresh->auth_version);
            }
            $valid = $fresh !== null && $fresh->isPlatformAdmin() && $fresh->status === UserStatus::Active
                && (int) $fresh->auth_version === (int) $request->session()->get('pos.auth_version', 0);
            if (! $valid) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                $response = $request->expectsJson()
                    ? response()->json(['message' => 'Your access has ended. Please sign in again.'], 401)
                    : redirect('/login');

                return $response->withCookie(Cookie::forget(Auth::guard('web')->getRecallerName()));
            }
            Auth::guard('web')->setUser($fresh);
        }

        return $next($request);
    }
}
