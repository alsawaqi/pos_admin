<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /auth/forgot-password (LAUNCH-P1 P1-8). Shape only: whether the
 * email belongs to an admin is never revealed (the endpoint always
 * answers 200).
 */
class ForgotPasswordRequest extends FormRequest
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
            'email' => ['required', 'email:rfc', 'max:191'],
        ];
    }
}
