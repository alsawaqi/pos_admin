<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\DeviceMake;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for POST /admin/api/v1/device-makes/{make}/models.
 *
 * The make is bound from the route, so `make_id` doesn't appear in
 * the payload — it's the URL. Name is unique within the chosen
 * make (the DB has a composite unique index enforcing this) so
 * Sunmi can have a "Pro" model and PAX can also have a "Pro"
 * model independently.
 */
class StoreDeviceModelRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var DeviceMake|null $make */
        $make = $this->route('make');
        $makeId = $make?->id;

        return [
            'name' => [
                'required', 'string', 'max:191',
                Rule::unique('pos_device_models', 'name')->where('make_id', $makeId),
            ],
            // Optional short identifier (e.g. "P2-MINI") for exports
            // / external integrations. Free text otherwise.
            'code' => ['nullable', 'string', 'max:64'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
