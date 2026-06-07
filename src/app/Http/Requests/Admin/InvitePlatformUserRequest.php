<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\PlatformRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the payload for POST /admin/api/v1/platform-team.
 *
 * `role` must be one of the seeded PlatformRole enum values — the
 * UI dropdown is built from the same enum so the only way an
 * invalid value lands here is via a hand-crafted request.
 *
 * Email uniqueness is platform-wide (no scoping by user_type)
 * because pos_users is shared with merchant portal users — a
 * merchant who later joins MITHQAL staff would need the merchant
 * account migrated, not duplicated.
 */
class InvitePlatformUserRequest extends FormRequest
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
            // Phone is optional. Same shape as portal-user invite.
            'phone' => ['nullable', 'string', 'max:32'],
            // Role is the spatie role name (string). Restrict to
            // the known set so a typo doesn't create a dangling
            // assignment row.
            'role' => ['required', 'string', Rule::in(PlatformRole::values())],
        ];
    }
}
