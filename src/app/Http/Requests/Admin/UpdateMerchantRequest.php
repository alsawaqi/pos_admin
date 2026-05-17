<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMerchantRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->route('merchant')?->id;

        return [
            'name' => ['sometimes', 'string', 'max:191'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:191'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'legal_name_ar' => ['sometimes', 'nullable', 'string', 'max:191'],

            'compliance' => ['sometimes', 'array'],
            'compliance.cr_number' => [
                'required_with:compliance', 'string', 'max:64',
                Rule::unique('pos_admin_companies', 'cr_number')->ignore($companyId)->whereNull('deleted_at'),
            ],
            'compliance.cr_issue_date' => ['nullable', 'date'],
            'compliance.cr_expiry_date' => ['nullable', 'date', 'after:compliance.cr_issue_date'],
            'compliance.establishment_date' => ['nullable', 'date', 'before_or_equal:today'],
            'compliance.tax_number' => ['nullable', 'string', 'max:64'],
            'compliance.vat_number' => ['nullable', 'string', 'max:64'],
            'compliance.vat_registered_at' => ['nullable', 'date', 'before_or_equal:today'],
            'compliance.chamber_of_commerce_number' => ['nullable', 'string', 'max:64'],
            'compliance.municipality_license_number' => ['nullable', 'string', 'max:64'],

            'contact' => ['sometimes', 'array'],
            'contact.name' => ['nullable', 'string', 'max:191'],
            'contact.phone' => ['nullable', 'string', 'max:32'],
            'contact.email' => ['nullable', 'email', 'max:191'],

            'owner' => ['sometimes', 'array'],
            'owner.full_name_en' => ['required_with:owner', 'string', 'max:191'],
            'owner.full_name_ar' => ['nullable', 'string', 'max:191'],
            'owner.civil_id' => ['nullable', 'string', 'max:32'],
            'owner.nationality' => ['nullable', 'string', 'size:2'],
            'owner.phone' => ['nullable', 'string', 'max:32'],
            'owner.email' => ['nullable', 'email', 'max:191'],

            'default_currency' => ['sometimes', 'string', 'size:3'],
            'default_locale' => ['sometimes', 'string', 'in:en,ar'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
