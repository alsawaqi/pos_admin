<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Role;

use App\Enums\PlatformPermission;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Spatie\Permission\Models\Role;

/**
 * Validates POST /admin/api/v1/roles.
 *
 *   name        — required, unique within the platform team
 *                 (spatie's UNIQUE is on team_id+name+guard,
 *                 so we cross-check at the validator layer for
 *                 a clean 422 instead of a 500-ish DB violation)
 *   description — optional
 *   permissions — optional array of PlatformPermission values
 */
class CreateRoleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(PlatformPermission::values())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $name = trim((string) $this->input('name'));
            if ($name === '') {
                return;
            }

            $taken = Role::query()
                ->where('name', $name)
                ->where('guard_name', 'web')
                ->where('team_id', TenantContext::PLATFORM_TEAM_ID)
                ->exists();
            if ($taken) {
                $v->errors()->add('name', 'A role with this name already exists.');
            }
        });
    }
}
