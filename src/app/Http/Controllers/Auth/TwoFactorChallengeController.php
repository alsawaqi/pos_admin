<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CompleteTwoFactorChallengeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Models\User;
use App\Services\Auth\JwtTokenService;
use App\Support\Auth\PendingTwoFactorChallenge;
use App\Support\Auth\PosAdminAuthPayload;
use App\ValueObjects\Auth\IssuedJwt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * The second step of a 2FA-enrolled admin login (Phase D8).
 *
 * AuthenticatedSessionController::store parks the pending state in
 * the session (NO session login, and crucially NO JWT cookie) and
 * bounces the browser to /two-factor-challenge; this controller is
 * the ONLY place that pending state can be converted into a real
 * session + JWT — a stolen password therefore never yields a valid
 * token despite 2FA:
 *
 *   GET  /auth/two-factor-challenge  is a challenge pending? (the
 *                                    SPA page redirects to /login
 *                                    when nothing is pending)
 *   POST /auth/two-factor-challenge  verify TOTP or burn a recovery
 *                                    code → complete the login
 *                                    exactly like the normal flow
 *                                    (session + JWT cookie)
 *
 * Code attempts are throttled per (pending user, IP) like login;
 * a correct code clears the counter. Success regenerates the
 * session id (anti-fixation) before the post-login keys are set.
 */
class TwoFactorChallengeController extends Controller
{
    public function __construct(
        private readonly JwtTokenService $jwtTokenService,
        private readonly PosAdminAuthPayload $authPayload,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'pending' => $this->pendingUser($request) !== null,
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(TwoFactorChallengeRequest $request, CompleteTwoFactorChallengeAction $action): JsonResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            PendingTwoFactorChallenge::clear($request->session());

            throw ValidationException::withMessages([
                'challenge' => [__('Your sign-in attempt expired. Please sign in again.')],
            ]);
        }

        $this->ensureIsNotRateLimited($request, $user);
        RateLimiter::hit($this->throttleKey($request, $user), 60);

        $validated = $request->validated();

        $passed = $action->handle(
            user: $user,
            code: $validated['code'] ?? null,
            recoveryCode: $validated['recovery_code'] ?? null,
        );

        if (! $passed) {
            throw ValidationException::withMessages([
                'code' => [__('The provided two-factor code is invalid.')],
            ]);
        }

        RateLimiter::clear($this->throttleKey($request, $user));

        $remember = PendingTwoFactorChallenge::remember($request->session());
        PendingTwoFactorChallenge::clear($request->session());

        // From here the flow mirrors AuthenticatedSessionController::
        // store after a password-only success — keep both in sync.
        // This is the FIRST (and only) place the JWT gets issued for
        // a 2FA-enrolled account.
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        $request->session()->put('pos_admin.remembered', $remember);
        $request->session()->put('pos_admin.last_activity_at', now()->timestamp);

        $jwt = $this->jwtTokenService->issueFor($user);

        return response()
            ->json([
                'user' => $this->authPayload->user($user),
                'token' => [
                    'type' => 'Bearer',
                    'access_token' => $jwt->accessToken,
                    'expires_at' => $jwt->expiresAt->toISOString(),
                ],
                'session' => $this->authPayload->session($request),
            ])
            ->withCookie($this->jwtCookie($jwt));
    }

    /**
     * Resolve the pending user — platform_admin rows only (the
     * shared pos_users table also hosts merchant users) and still
     * active. NULL when nothing is pending or the challenge expired.
     */
    private function pendingUser(Request $request): ?User
    {
        $userId = PendingTwoFactorChallenge::userId($request->session());

        if ($userId === null) {
            return null;
        }

        /** @var User|null $user */
        $user = User::query()
            ->platformAdmin()
            ->where('status', 'active')
            ->find($userId);

        if ($user === null || ! $user->hasConfirmedTwoFactor()) {
            return null;
        }

        return $user;
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(Request $request, User $user): void
    {
        $key = $this->throttleKey($request, $user);
        $max = (int) config('pos_admin_auth.rate_limits.two_factor_per_minute', 5);

        if (! RateLimiter::tooManyAttempts($key, $max)) {
            return;
        }

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'code' => [trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ])],
        ]);
    }

    private function throttleKey(Request $request, User $user): string
    {
        return 'two_factor|'.$user->id.'|'.$request->ip();
    }

    /**
     * Same construction as AuthenticatedSessionController::jwtCookie
     * — keep both in sync.
     */
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
