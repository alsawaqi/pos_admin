<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCountryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $countryId = $this->route('country')?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:191'],
            'iso_code' => ['sometimes', 'required', 'string', 'size:2', Rule::unique('countries', 'iso_code')->ignore($countryId)],
            'phone_code' => ['sometimes', 'nullable', 'string', 'max:16'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
