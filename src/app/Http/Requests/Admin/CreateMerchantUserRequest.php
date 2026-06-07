<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for POST /admin/api/v1/merchants/{uuid}/portal-users.
 *
 * Three fields: name, email, phone. No branch scope picker —
 * initial users are unscoped super admins by definition (see
 * CreateMerchantUserAction). No password input — the action
 * generates it server-side and returns the plaintext once.
 *
 * Email uniqueness is platform-wide (no scoping by user_type)
 * because pos_users is shared with platform admins — overlap would
 * confuse the auth guard.
 */
class CreateMerchantUserRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'email' => [
                'required', 'email:rfc', 'max:191',
                Rule::unique('pos_users', 'email'),
            ],
            'phone' => ['nullable', 'string', 'max:32'],
        ];
    }
}
