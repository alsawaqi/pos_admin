<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Auth\JwtTokenService;
use App\Support\Auth\PosAdminAuthPayload;
use App\ValueObjects\Auth\IssuedJwt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Dual-mode session controller.
 *
 * The login form is now a native HTML POST so the browser handles the
 * boundary crossing (no XHR + window.location race). The methods detect
 * whether the caller is an XHR client or a form submission and respond
 * accordingly:
 *
 *   - XHR (Accept: application/json): JSON payload + JWT cookie (legacy
 *     client and feature tests stay unchanged).
 *   - Form: 302 redirect to /admin on success, redirect-back with
 *     flashed errors on failure.
 */
class AuthenticatedSessionController extends Controller
{
    public function __construct(
        private readonly JwtTokenService $jwtTokenService,
        private readonly PosAdminAuthPayload $authPayload,
    ) {}

    /**
     * @throws ValidationException
     */
    public function store(LoginRequest $request): JsonResponse|RedirectResponse
    {
        $alreadyAuthed = Auth::guard('web')->check();

        if (! $alreadyAuthed) {
            // Rate limit is checked + incremented only around the credential
            // attempt itself. Successful logins clear the counter so testing
            // (and any legitimate retry) does not eat the quota.
            $request->ensureIsNotRateLimited();

            if (! Auth::guard('web')->attempt($request->credentials(), $request->remember())) {
                RateLimiter::hit($request->throttleKey(), 60);

                return $this->failedLogin($request);
            }

            RateLimiter::clear($request->throttleKey());
            $request->session()->regenerate();
        }

        $request->session()->put('pos_admin.remembered', $request->remember());
        $request->session()->put('pos_admin.last_activity_at', now()->timestamp);

        /** @var User $user */
        $user = Auth::guard('web')->user();
        $jwt = $this->jwtTokenService->issueFor($user);

        if ($request->expectsJson()) {
            return response()
                ->json([
                    'user' => $this->userPayload($user),
                    'token' => $this->tokenPayload($jwt),
                    'session' => $this->authPayload->session($request),
                ])
                ->withCookie($this->jwtCookie($jwt));
        }

        return redirect()
            ->intended('/admin')
            ->withCookie($this->jwtCookie($jwt));
    }

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => $this->userPayload($user),
            'session' => $this->authPayload->session($request),
        ]);
    }

    public function destroy(Request $request): Response|RedirectResponse
    {
        Auth::guard('web')->logout();

        // invalidate() destroys the session in the configured driver and
        // generates a new session ID; the framework writes the new (empty)
        // session cookie on response send. regenerateToken() rotates the
        // CSRF token so any leaked-token replay is dead on arrival.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $response = $request->expectsJson()
            ? response()->noContent()
            : redirect('/login');

        // Defence in depth — actively expire the cookies a hostile or
        // confused browser might still be carrying so the next request
        // from this client is unambiguously anonymous:
        //   - the JWT cookie (private auth credential)
        //   - the recaller cookie that Laravel uses for "remember me"
        $response->withCookie(Cookie::forget((string) config('pos_admin_auth.jwt.cookie')));
        $response->withCookie(Cookie::forget(Auth::guard('web')->getRecallerName()));

        return $response;
    }

    /**
     * @throws ValidationException
     */
    private function failedLogin(LoginRequest $request): RedirectResponse
    {
        if ($request->expectsJson()) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        return back()
            ->withErrors(['email' => __('auth.failed')])
            ->withInput($request->only('email', 'remember'));
    }

    /**
     * @return array{id: int|string|null, name: string|null, email: string|null, user_type: string|null, status: string|null, roles: list<string>, permissions: list<string>}
     */
    private function userPayload(User $user): array
    {
        return $this->authPayload->user($user);
    }

    /**
     * @return array{type: string, access_token: string, expires_at: string}
     */
    private function tokenPayload(IssuedJwt $jwt): array
    {
        return [
            'type' => 'Bearer',
            'access_token' => $jwt->accessToken,
            'expires_at' => $jwt->expiresAt->toISOString(),
        ];
    }

    private function jwtCookie(IssuedJwt $jwt): SymfonyCookie
    {
        return Cookie::make(
            name: (string) config('pos_admin_auth.jwt.cookie'),
            value: $jwt->accessToken,
            minutes: (int) config('pos_admin_auth.jwt.ttl_minutes'),
            path: '/',
            domain: config('session.domain'),
            secure: (bool) config('session.secure'),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }
}
