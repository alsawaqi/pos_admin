<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\CompanyStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMerchantRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'name_ar' => ['nullable', 'string', 'max:191'],
            'legal_name' => ['nullable', 'string', 'max:191'],
            'legal_name_ar' => ['nullable', 'string', 'max:191'],

            'compliance' => ['required', 'array'],
            'compliance.cr_number' => ['required', 'string', 'max:64', Rule::unique('pos_admin_companies', 'cr_number')->whereNull('deleted_at')],
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

            'owner' => ['required', 'array'],
            'owner.full_name_en' => ['required', 'string', 'max:191'],
            'owner.full_name_ar' => ['nullable', 'string', 'max:191'],
            'owner.civil_id' => ['nullable', 'string', 'max:32'],
            'owner.nationality' => ['nullable', 'string', 'size:2'],
            'owner.phone' => ['nullable', 'string', 'max:32'],
            'owner.email' => ['nullable', 'email', 'max:191'],

            'activities' => ['nullable', 'array'],
            'activities.*.business_activity_id' => ['required_with:activities', 'integer', 'exists:pos_admin_business_activities,id'],
            'activities.*.is_primary' => ['nullable', 'boolean'],

            'default_currency' => ['nullable', 'string', 'size:3'],
            'default_locale' => ['nullable', 'string', 'in:en,ar'],
            'status' => ['nullable', Rule::enum(CompanyStatus::class)],
            'settings' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
