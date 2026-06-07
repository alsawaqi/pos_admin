<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\CompanyStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for POST /admin/api/v1/merchants.
 *
 * Owners are now an ARRAY of objects (was a single object). Rules
 * here cover per-element shape; the `withValidator` callback adds
 * the cross-element "exactly one primary" check.
 */
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

            // owners[] — at least one row required.
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

            'default_currency' => ['nullable', 'string', 'size:3'],
            'default_locale' => ['nullable', 'string', 'in:en,ar'],
            'status' => ['nullable', Rule::enum(CompanyStatus::class)],
            'settings' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Cross-field check that exactly one owner in the array carries
     * `is_primary: true`. Without this guard the wizard could submit
     * 0 or 2+ primaries and the system would have an ambiguous
     * canonical owner.
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
