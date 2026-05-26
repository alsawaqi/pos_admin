<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\PlatformRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the payload for PATCH /admin/api/v1/platform-team/{user}.
 *
 * Every field is optional — partial updates are fine. The Action
 * only acts on keys that are actually present in the payload, so
 * sending just `{role: ...}` rotates the role without touching name
 * or phone.
 *
 * Email + status are NOT updatable here:
 *   - email rotation is intentionally out of scope (see action docs)
 *   - status changes go through dedicated suspend/reactivate
 *     endpoints so each is its own audit event
 */
class UpdatePlatformUserRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:191'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'role' => ['sometimes', 'string', Rule::in(PlatformRole::values())],
        ];
    }
}
