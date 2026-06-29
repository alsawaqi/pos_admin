<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for PATCH /admin/api/v1/marketing/advertisers/{advertiser}.
 * Only the keys present are applied. Email + password are not editable here
 * (email is the login id; password has a dedicated reset endpoint).
 */
class UpdateAdvertiserRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'brand_name' => ['sometimes', 'required', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'status' => ['sometimes', 'required', Rule::in(['active', 'suspended'])],
            'is_merchant' => ['sometimes', 'boolean'],
            'company_id' => ['sometimes', 'nullable', 'integer', Rule::exists('pos_companies', 'id')],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // If this request turns the advertiser into a merchant it must carry
            // (or already have via the model — enforced in the action) a company.
            if ($this->has('is_merchant') && $this->boolean('is_merchant') && $this->has('company_id') && ! $this->filled('company_id')) {
                $v->errors()->add('company_id', 'A merchant advertiser must be linked to a company.');
            }
        });
    }
}
