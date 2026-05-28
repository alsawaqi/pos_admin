<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for PATCH /admin/api/v1/merchants/{uuid}.
 *
 * Every section uses `sometimes` so the caller can update one slice
 * at a time. When `owners` IS present, it must follow the same
 * shape as the Store request (≥1 row, exactly one primary) because
 * the UpdateCompanyAction SYNCs (wipes + recreates) the full set.
 */
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
                Rule::unique('pos_companies', 'cr_number')->ignore($companyId)->whereNull('deleted_at'),
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

            // owners[] is OPTIONAL on update — if absent, owners stay
            // as they are. If present, the rules mirror the Store
            // request and the action wipes + recreates the full set.
            'owners' => ['sometimes', 'array', 'min:1'],
            'owners.*.full_name_en' => ['required', 'string', 'max:191'],
            'owners.*.full_name_ar' => ['nullable', 'string', 'max:191'],
            'owners.*.civil_id' => ['nullable', 'string', 'max:32'],
            'owners.*.nationality' => ['nullable', 'string', 'size:2'],
            'owners.*.phone' => ['nullable', 'string', 'max:32'],
            'owners.*.email' => ['nullable', 'email', 'max:191'],
            'owners.*.is_primary' => ['required', 'boolean'],
            'owners.*.ownership_percentage' => ['nullable', 'numeric', 'between:0,100'],

            'default_currency' => ['sometimes', 'string', 'size:3'],
            'default_locale' => ['sometimes', 'string', 'in:en,ar'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Same "exactly one primary owner" guard as Store. Runs only when
     * owners[] was sent; if it wasn't, this is a no-op.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (! $this->has('owners')) {
                return;
            }

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
