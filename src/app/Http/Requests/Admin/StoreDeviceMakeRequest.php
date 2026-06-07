<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for POST /admin/api/v1/device-makes (Settings →
 * Device catalogue → "+ New make").
 *
 * Name is globally unique — there should be exactly one "Sunmi"
 * row in the catalogue, not three near-duplicates.
 */
class StoreDeviceMakeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191', Rule::unique('pos_device_makes', 'name')],
            // Lower numbers render earlier in the cascading dropdown.
            'display_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            // Defaults to true on the model side; included here so
            // admins can pre-deactivate a make they're staging.
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
