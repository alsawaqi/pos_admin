<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Support\Auth\AdminPasswordRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /auth/change-password (LAUNCH-P1 P1-8) — the signed-in admin
 * changes their own password. The current password is verified in the
 * controller.
 */
class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => [...AdminPasswordRules::rules(), 'different:current_password'],
        ];
    }
}
