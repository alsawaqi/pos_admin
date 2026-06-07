<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCountryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'iso_code' => ['required', 'string', 'size:2', Rule::unique('countries', 'iso_code')],
            'phone_code' => ['nullable', 'string', 'max:16'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
