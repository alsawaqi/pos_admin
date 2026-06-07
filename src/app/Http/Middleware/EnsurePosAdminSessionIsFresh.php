<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsurePosAdminSessionIsFresh
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return $next($request);
        }

        $session = $request->session();
        $remembered = (bool) $session->get('pos_admin.remembered', false);
        $lastActivityAt = (int) $session->get('pos_admin.last_activity_at', now()->timestamp);
        $timeoutSeconds = ((int) config('pos_admin_auth.session.idle_timeout_minutes')) * 60;

        if (! $remembered && $timeoutSeconds > 0 && (now()->timestamp - $lastActivityAt) > $timeoutSeconds) {
            Auth::guard('web')->logout();
            $session->invalidate();
            $session->regenerateToken();

            return $this->expiredResponse($request);
        }

        $session->put('pos_admin.last_activity_at', now()->timestamp);

        return $next($request);
    }

    private function expiredResponse(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Your session has expired. Please sign in again.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return redirect()->guest(route('login'));
    }
}
