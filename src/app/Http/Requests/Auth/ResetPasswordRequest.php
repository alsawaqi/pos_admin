<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Support\Auth\AdminPasswordRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /auth/reset-password (LAUNCH-P1 P1-8) — consumes an admin's
 * set-password link (invite, admin reset or forgot-password). The
 * token's validity is checked by CompleteAdminPasswordLinkAction with a
 * single generic failure message.
 */
class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc'],
            'token' => ['required', 'string', 'max:255'],
            'password' => AdminPasswordRules::rules(),
        ];
    }
}
