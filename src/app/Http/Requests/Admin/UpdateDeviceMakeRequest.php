<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for PATCH /admin/api/v1/device-makes/{make}.
 *
 * Every field uses `sometimes` so the admin can flip just
 * is_active without re-sending name + display_order.
 */
class UpdateDeviceMakeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $makeId = $this->route('make')?->id;

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:191',
                Rule::unique('pos_device_makes', 'name')->ignore($makeId),
            ],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
