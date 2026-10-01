<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CompleteAdminPasswordLinkAction;
use App\Actions\Auth\SendAdminPasswordResetLinkAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin portal forgot / set / reset password endpoints (LAUNCH-P1 P1-8).
 * Mirrors pos_merchant's PasswordResetController.
 *
 *   POST /auth/forgot-password — always 200 (anti-enumeration); mails a
 *                                60-minute link to an active admin.
 *   POST /auth/reset-password  — consumes any admin set-password link
 *                                (invite, admin reset, forgot).
 *
 * Both are throttled per (email, IP) in 15-minute windows; a successful
 * reset clears its bucket.
 */
class PasswordResetController extends Controller
{
    private const THROTTLE_DECAY_SECONDS = 900;

    /**
     * @throws ValidationException
     */
    public function forgot(ForgotPasswordRequest $request, SendAdminPasswordResetLinkAction $action): JsonResponse
    {
        $this->ensureIsNotRateLimited($request, 'forgot');
        RateLimiter::hit($this->throttleKey($request, 'forgot'), self::THROTTLE_DECAY_SECONDS);

        $action->handle((string) $request->string('email'));

        return response()->json([
            'message' => 'If an admin account exists for that email, a reset link has been sent.',
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function reset(ResetPasswordRequest $request, CompleteAdminPasswordLinkAction $action): JsonResponse
    {
        $this->ensureIsNotRateLimited($request, 'reset');
        RateLimiter::hit($this->throttleKey($request, 'reset'), self::THROTTLE_DECAY_SECONDS);

        $validated = $request->validated();

        $action->handle(
            email: (string) $validated['email'],
            rawToken: (string) $validated['token'],
            password: (string) $validated['password'],
        );

        RateLimiter::clear($this->throttleKey($request, 'reset'));

        return response()->json([
            'message' => 'Your password has been set. You can sign in now.',
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(Request $request, string $bucket): void
    {
        $key = $this->throttleKey($request, $bucket);
        $max = (int) config('pos_admin_auth.rate_limits.password_reset_per_quarter_hour', 5);

        if (! RateLimiter::tooManyAttempts($key, $max)) {
            return;
        }

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ]),
        ]);
    }

    private function throttleKey(Request $request, string $bucket): string
    {
        $email = Str::lower((string) $request->string('email'));

        return 'admin_password_reset|'.$bucket.'|'.Str::transliterate($email.'|'.$request->ip());
    }
}
