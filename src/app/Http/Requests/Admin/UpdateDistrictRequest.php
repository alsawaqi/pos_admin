<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDistrictRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'region_id' => ['sometimes', 'required', 'integer', 'exists:regions,id'],
            'name' => ['sometimes', 'required', 'string', 'max:191'],
        ];
    }
}
