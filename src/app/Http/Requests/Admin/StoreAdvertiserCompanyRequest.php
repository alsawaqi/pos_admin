<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for POST /admin/api/v1/marketing/advertisers/with-company — the
 * admin onboards a brand-new advertising-only company AND its marketing portal
 * login in one step.
 *
 * The company half mirrors {@see StoreMerchantRequest} (company info, owners,
 * activities) MINUS the commission step. The login half lives under `account`
 * — only email + password are required; brand / contact name / phone / category
 * fall back to the company details server-side.
 */
class StoreAdvertiserCompanyRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // --- Company (mirrors StoreMerchantRequest, no commission) -------
            'name' => ['required', 'string', 'max:191'],
            'name_ar' => ['nullable', 'string', 'max:191'],
            'legal_name' => ['nullable', 'string', 'max:191'],
            'legal_name_ar' => ['nullable', 'string', 'max:191'],

            'compliance' => ['required', 'array'],
            'compliance.cr_number' => ['required', 'string', 'max:64', Rule::unique('pos_companies', 'cr_number')->whereNull('deleted_at')],
            'compliance.cr_issue_date' => ['nullable', 'date'],
            'compliance.cr_expiry_date' => ['nullable', 'date', 'after:compliance.cr_issue_date'],
            'compliance.establishment_date' => ['nullable', 'date', 'before_or_equal:today'],
            'compliance.tax_number' => ['nullable', 'string', 'max:64'],
            'compliance.vat_number' => ['nullable', 'string', 'max:64'],
            'compliance.vat_registered_at' => ['nullable', 'date', 'before_or_equal:today'],
            'compliance.chamber_of_commerce_number' => ['nullable', 'string', 'max:64'],
            'compliance.municipality_license_number' => ['nullable', 'string', 'max:64'],

            'contact' => ['required', 'array'],
            'contact.name' => ['nullable', 'string', 'max:191'],
            'contact.phone' => ['nullable', 'string', 'max:32'],
            'contact.email' => ['nullable', 'email', 'max:191'],

            'owners' => ['required', 'array', 'min:1'],
            'owners.*.full_name_en' => ['required', 'string', 'max:191'],
            'owners.*.full_name_ar' => ['nullable', 'string', 'max:191'],
            'owners.*.civil_id' => ['nullable', 'string', 'max:32'],
            'owners.*.nationality' => ['nullable', 'string', 'size:2'],
            'owners.*.phone' => ['nullable', 'string', 'max:32'],
            'owners.*.email' => ['nullable', 'email', 'max:191'],
            'owners.*.is_primary' => ['required', 'boolean'],
            'owners.*.ownership_percentage' => ['nullable', 'numeric', 'between:0,100'],

            'activities' => ['nullable', 'array'],
            'activities.*.business_activity_id' => ['required_with:activities', 'integer', 'exists:pos_business_activities,id'],
            'activities.*.is_primary' => ['nullable', 'boolean'],

            // --- Marketing portal login -------------------------------------
            'account' => ['required', 'array'],
            'account.email' => ['required', 'email', 'max:191', Rule::unique('advertisers', 'email')],
            'account.password' => ['required', 'string', 'min:8', 'max:191'],
            'account.brand_name' => ['nullable', 'string', 'max:120'],
            'account.contact_name' => ['nullable', 'string', 'max:120'],
            'account.phone' => ['nullable', 'string', 'max:40'],
            'account.category' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Exactly one owner must carry `is_primary: true` — same guard as the
     * merchant wizard so the advertising company has an unambiguous person of
     * record.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            /** @var array<int, array<string, mixed>> $owners */
            $owners = $this->input('owners', []);
            $primaryCount = 0;
            foreach ($owners as $owner) {
                if (! empty($owner['is_primary'])) {
                    $primaryCount++;
                }
            }

            if ($primaryCount !== 1) {
                $v->errors()->add('owners', 'Exactly one owner must be marked as primary.');
            }
        });
    }
}
