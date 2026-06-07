<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRegionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $region = $this->route('region');
        $regionId = $region?->id;
        $countryId = $this->input('country_id', $region?->country_id);

        return [
            'country_id' => ['sometimes', 'required', 'integer', 'exists:countries,id'],
            'name' => [
                'sometimes', 'required', 'string', 'max:191',
                Rule::unique('regions', 'name')
                    ->where(fn ($query) => $query->where('country_id', $countryId))
                    ->ignore($regionId),
            ],
            'type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'code' => ['sometimes', 'nullable', 'string', 'max:191'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
