<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRegionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'name' => [
                'required', 'string', 'max:191',
                Rule::unique('regions', 'name')->where(
                    fn ($query) => $query->where('country_id', $this->input('country_id')),
                ),
            ],
            'type' => ['nullable', 'string', 'max:50'],
            'code' => ['nullable', 'string', 'max:191'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
