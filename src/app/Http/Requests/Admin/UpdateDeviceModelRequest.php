<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\DeviceMake;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for PATCH
 * /admin/api/v1/device-makes/{make}/models/{model}.
 *
 * Every field optional. The unique-name rule scopes to the current
 * make and ignores the current model row so saving without changing
 * the name doesn't trip the duplicate check.
 */
class UpdateDeviceModelRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var DeviceMake|null $make */
        $make = $this->route('make');
        $makeId = $make?->id;
        $modelId = $this->route('model')?->id;

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:191',
                Rule::unique('pos_device_models', 'name')
                    ->where('make_id', $makeId)
                    ->ignore($modelId),
            ],
            'code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
