<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\UserStatus;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for PATCH /admin/api/v1/merchants/{merchant}/portal-users/{user}.
 *
 * Every field uses `sometimes` so the admin can flip one thing at
 * a time (e.g. just suspend without re-sending the branch scope).
 */
class UpdatePortalUserRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Company|null $merchant */
        $merchant = $this->route('merchant');
        $companyId = $merchant?->id;

        return [
            // Active / Inactive / Suspended — the same enum the
            // model casts to. Suspend = admin-driven lockout;
            // Inactive normally means "invited but not yet
            // completed setup".
            'status' => ['sometimes', Rule::enum(UserStatus::class)],

            // null = "grant all branches"; array = restricted set.
            // We accept both shapes by allowing the field to be
            // null OR an array. Each id must belong to this merchant.
            'branch_scope' => ['sometimes', 'nullable', 'array'],
            'branch_scope.*' => [
                'integer',
                Rule::exists('pos_branches', 'id')->where('company_id', $companyId),
            ],

            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }
}
