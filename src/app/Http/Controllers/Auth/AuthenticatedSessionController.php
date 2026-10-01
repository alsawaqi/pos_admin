<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Auth\JwtTokenService;
use App\Support\Auth\PendingTwoFactorChallenge;
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
    /**
     * Shown to an admin with no usable password (reset by another admin,
     * or invited and not set up yet): their old password is gone on
     * purpose and only the set-password link works.
     */
    public const AWAITING_LINK_MESSAGE = 'Your password was reset or has not been set yet. Open the set-password link you received to choose a new one. If the link expired, use "Forgot password?" or ask another admin for a new link.';

    public function __construct(
        private readonly JwtTokenService $jwtTokenService,
        private readonly PosAdminAuthPayload $authPayload,
        private readonly WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * HMAC of the normalised typed email: the same address always gives
     * the same value (so repeated attempts can be grouped) without the
     * audit log holding the address itself.
     */
    private static function emailFingerprint(string $typed): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($typed)), (string) config('app.key'));
    }

    /**
     * "a***@example.com" — first character of the local part, the domain
     * capped at 64 characters, at most 80 characters in total.
     */
    private static function maskEmail(string $typed): string
    {
        [$local, $domain] = array_pad(explode('@', trim($typed), 2), 2, '');
        $masked = mb_substr($local, 0, 1).'***';
        if ($domain !== '') {
            $masked .= '@'.mb_substr($domain, 0, 64);
        }

        return mb_substr($masked, 0, 80);
    }

    /**
     * LAUNCH-P1 low finding: admin sign-ins (and failures) are audited.
     * Never records the password.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function auditLogin(string $event, ?User $user, array $metadata): void
    {
        $this->writeAuditLog->handle(new AuditLogData(
            event: $event,
            actorUserId: $event === 'platform_user.login_failed' ? null : $user?->id,
            auditableType: $user === null ? null : User::class,
            auditableId: $user?->id,
            metadata: $metadata,
        ));
    }

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

            // Restrict the candidate user pool to platform_admin rows
            // BEFORE attempting the password check. Without this, a
            // merchant credential pair (same hashed password column,
            // user_type='merchant') would satisfy Auth::attempt() and
            // land the merchant inside /admin with whatever roles
            // happen to be scoped to the platform team. Using
            // ->validate() instead of ->attempt() means the session
            // isn't auto-created until AFTER the type check passes —
            // important because attempt() side-effects the session
            // even on legacy-shaped success.
            $candidate = User::query()
                ->platformAdmin()
                ->where('email', $request->credentials()['email'])
                ->first();

            $passwordOk = $candidate !== null
                && $candidate->status === UserStatus::Active
                && Auth::guard('web')->validate($request->credentials())
                && $candidate->isPlatformAdmin();

            if (! $passwordOk) {
                RateLimiter::hit($request->throttleKey(), 60);

                // Owner follow-up 2026-10-01: an admin whose password was
                // reset (or never set) has NO usable password — tell them to
                // use their set-password link instead of "wrong password".
                $awaitingLink = $candidate !== null
                    && $candidate->status === UserStatus::Active
                    && $candidate->password === null;

                // LAUNCH-P1 low finding: failed admin logins are audited
                // (credential-stuffing and lost-access signal).
                // Review finding: never store the raw typed text (it can be
                // a mistyped password or anyone's address) — a keyed hash
                // to correlate attempts plus a short masked form.
                $this->auditLogin('platform_user.login_failed', $candidate, [
                    'email_hash' => self::emailFingerprint($request->credentials()['email']),
                    'email_masked' => self::maskEmail($request->credentials()['email']),
                    'reason' => match (true) {
                        $candidate === null => 'unknown_email',
                        $candidate->status !== UserStatus::Active => 'inactive_account',
                        $awaitingLink => 'password_reset_pending',
                        default => 'wrong_password',
                    },
                ]);

                return $this->failedLogin($request, $awaitingLink ? self::AWAITING_LINK_MESSAGE : null);
            }

            // Phase D8 — a TOTP-enrolled account does NOT get a
            // session OR a JWT cookie from the password alone. Park
            // a short-lived pending state (server-side session,
            // anti-fixation regenerate inside begin()) and send the
            // browser to the code page; TwoFactorChallengeController
            // is the only place that converts it into a real login +
            // JWT issuance.
            if ($candidate->hasConfirmedTwoFactor()) {
                RateLimiter::clear($request->throttleKey());
                PendingTwoFactorChallenge::begin($request->session(), $candidate, $request->remember());
                $this->auditLogin('platform_user.login_password_passed', $candidate, ['next' => 'two_factor_challenge']);

                if ($request->expectsJson()) {
                    return response()->json(['two_factor' => true]);
                }

                return redirect()->to('/two-factor-challenge');
            }

            Auth::guard('web')->login($candidate, $request->remember());
            RateLimiter::clear($request->throttleKey());
            $request->session()->regenerate();
            $request->session()->put('pos.auth_version', (int) $candidate->auth_version);
            $this->auditLogin('platform_user.login_succeeded', $candidate, [
                'method' => 'password',
                // P1-15: an admin without an authenticator lands on the
                // setup page and can do nothing else until it is done.
                'two_factor_setup_required' => (bool) config('pos_admin_auth.two_factor.required', true),
            ]);
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

        // No Clear-Site-Data here. PreventBackHistoryCache already stamps
        // every response with Cache-Control: no-store + Vary: Cookie, which
        // is the modern way to keep the /login HTML out of bfcache without
        // racing the Set-Cookie headers on this same response.
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

        // Drop the back/forward cache so a Back press after logout can NEVER
        // restore the previously-rendered /admin shell. We deliberately do
        // NOT include "cookies" or "storage": Cookie::forget() above plus
        // session()->invalidate() are the authoritative clearing mechanism,
        // and mixing in Clear-Site-Data: cookies races the next request's
        // Set-Cookie headers (the /login GET that follows this redirect),
        // which leaves the next login attempt without a usable session
        // cookie and forces a second click.
        $response->headers->set('Clear-Site-Data', '"cache"');

        // Cache-Control / Pragma / Expires are stamped by PreventBackHistoryCache.

        return $response;
    }

    /**
     * @throws ValidationException
     */
    private function failedLogin(LoginRequest $request, ?string $message = null): RedirectResponse
    {
        $message ??= __('auth.failed');

        if ($request->expectsJson()) {
            throw ValidationException::withMessages([
                'email' => $message,
            ]);
        }

        return back()
            ->withErrors(['email' => $message])
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
