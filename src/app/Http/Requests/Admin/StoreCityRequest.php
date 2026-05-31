<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCityRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'region_id' => ['required', 'integer', 'exists:regions,id'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'name' => [
                'required', 'string', 'max:191',
                Rule::unique('cities', 'name')->where(
                    fn ($query) => $query->where('region_id', $this->input('region_id')),
                ),
            ],
            'postal_code' => ['nullable', 'string', 'max:191'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
