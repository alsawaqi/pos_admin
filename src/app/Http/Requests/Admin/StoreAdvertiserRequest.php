<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for POST /admin/api/v1/marketing/advertisers — the admin creates
 * the advertiser account + login. A merchant advertiser must be linked to an
 * existing pos_companies company.
 */
class StoreAdvertiserRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'brand_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191', Rule::unique('advertisers', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:191'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_merchant' => ['nullable', 'boolean'],
            'company_id' => ['nullable', 'integer', Rule::exists('pos_companies', 'id')],
            'category' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->boolean('is_merchant') && ! $this->filled('company_id')) {
                $v->errors()->add('company_id', 'A merchant advertiser must be linked to a company.');
            }
        });
    }
}
