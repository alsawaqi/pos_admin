<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Auth\JwtTokenService;
use App\ValueObjects\Auth\IssuedJwt;
use BackedEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
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
    ) {}

    /**
     * @throws ValidationException
     */
    public function store(LoginRequest $request): JsonResponse|RedirectResponse
    {
        $alreadyAuthed = Auth::guard('web')->check();

        if (! $alreadyAuthed) {
            if (! Auth::guard('web')->attempt($request->credentials(), $request->remember())) {
                return $this->failedLogin($request);
            }

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
                    'session' => $this->sessionPayload($request),
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
            'session' => $this->sessionPayload($request),
        ]);
    }

    public function destroy(Request $request): Response|RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $forgetCookie = Cookie::forget((string) config('pos_admin_auth.jwt.cookie'));

        if ($request->expectsJson()) {
            return response()
                ->noContent()
                ->withCookie($forgetCookie);
        }

        return redirect('/login')->withCookie($forgetCookie);
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
        $userType = $user->getAttribute('user_type');
        $status = $user->getAttribute('status');

        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'user_type' => $this->enumValue($userType),
            'status' => $this->enumValue($status),
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ];
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) ? $value : null;
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

    /**
     * @return array{remembered: bool, idle_timeout_seconds: int, last_activity_at: int|null}
     */
    private function sessionPayload(Request $request): array
    {
        return [
            'remembered' => (bool) $request->session()->get('pos_admin.remembered', false),
            'idle_timeout_seconds' => ((int) config('pos_admin_auth.session.idle_timeout_minutes')) * 60,
            'last_activity_at' => $request->session()->get('pos_admin.last_activity_at'),
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
