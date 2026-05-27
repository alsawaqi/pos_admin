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
 * Validates PATCH /admin/api/v1/roles/{role}. All fields
 * `sometimes` — partial update. Name uniqueness re-checked
 * excluding the current row so a no-op name resubmit isn't
 * flagged.
 */
class UpdateRoleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:64'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(PlatformPermission::values())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (! $this->has('name')) {
                return;
            }
            $name = trim((string) $this->input('name'));
            if ($name === '') {
                return;
            }

            /** @var Role|null $current */
            $current = $this->route('role');
            $currentId = $current?->id ?? 0;

            $taken = Role::query()
                ->where('name', $name)
                ->where('guard_name', 'web')
                ->where('team_id', TenantContext::PLATFORM_TEAM_ID)
                ->where('id', '!=', $currentId)
                ->exists();
            if ($taken) {
                $v->errors()->add('name', 'A role with this name already exists.');
            }
        });
    }
}
