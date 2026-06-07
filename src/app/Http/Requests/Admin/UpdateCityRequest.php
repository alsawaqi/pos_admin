<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCityRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $city = $this->route('city');
        $cityId = $city?->id;
        $regionId = $this->input('region_id', $city?->region_id);

        return [
            'region_id' => ['sometimes', 'required', 'integer', 'exists:regions,id'],
            'district_id' => ['sometimes', 'nullable', 'integer', 'exists:districts,id'],
            'name' => [
                'sometimes', 'required', 'string', 'max:191',
                Rule::unique('cities', 'name')
                    ->where(fn ($query) => $query->where('region_id', $regionId))
                    ->ignore($cityId),
            ],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:191'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
