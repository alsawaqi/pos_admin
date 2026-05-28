<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for POST /admin/api/v1/merchants/{merchant}/portal-users.
 *
 * Email must be globally unique across the pos_users table — the
 * merchant portal uses email as the login id, and merging two
 * portal accounts under one email is not a flow we support.
 *
 * branch_scope is an OPTIONAL array of branch ids. Omit entirely
 * to grant "all branches" access (the implicit default for the
 * merchant's Super Admin user). When present, every id must
 * actually belong to this merchant — checked via `exists` rule
 * scoped to the URL-bound company.
 */
class InvitePortalUserRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191', Rule::unique('pos_users', 'email')],
            // Optional phone — useful for support/2FA bootstrap. No
            // strict format check because Oman numbers, +968, GCC
            // numbers, and international staff numbers all coexist.
            'phone' => ['nullable', 'string', 'max:32'],

            // Specific branch scope, optional. NULL/missing = all
            // branches. Each id must belong to this merchant
            // (`->where('company_id', $companyId)` on the rule).
            'branch_scope' => ['nullable', 'array'],
            'branch_scope.*' => [
                'integer',
                Rule::exists('pos_branches', 'id')->where('company_id', $companyId),
            ],
        ];
    }
}
