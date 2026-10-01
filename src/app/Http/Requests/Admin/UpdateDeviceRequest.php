<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\DeviceType;
use App\Models\DeviceModel;
use App\Support\DeviceSerial;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates PATCH /admin/api/v1/devices/{device:uuid} — editing a registered
 * device's identity + catalogue + commission/organization bindings.
 *
 * Every field is `sometimes` (partial update): only the keys the admin actually
 * changed are sent, validated, and written (see {@see UpdateDeviceData} +
 * {@see \App\Actions\Admin\UpdateDeviceAction}). terminal_id + bank_id are NOT
 * editable here — they belong to the ASSIGN flow (issued against the merchant's
 * bank account); company_id / branch_id / status are managed by assign /
 * unassign / decommission, never a vanilla field edit.
 */
class UpdateDeviceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $deviceId = $this->route('device')?->id;

        return [
            'serial_number' => [
                'sometimes', 'filled', 'string', 'max:128',
                Rule::unique('pos_devices', 'serial_number')->ignore($deviceId),
            ],
            'kiosk_id' => [
                'sometimes', 'string', 'max:128',
                Rule::unique('pos_devices', 'kiosk_id')->ignore($deviceId),
            ],

            'commission_profile_id' => [
                'sometimes', 'integer',
                Rule::exists('commission_profiles', 'id'),
            ],
            'organization_id' => [
                'sometimes', 'integer',
                Rule::exists('organizations', 'id'),
            ],

            'name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'label' => ['sometimes', 'nullable', 'string', 'max:128'],

            'make_id' => ['sometimes', 'integer', Rule::exists('pos_device_makes', 'id')],
            'model_id' => ['sometimes', 'integer', Rule::exists('pos_device_models', 'id')],

            'device_type' => ['sometimes', Rule::enum(DeviceType::class)],
        ];
    }

    /**
     * LAUNCH-P1 P1-12: serials are stored and compared normalised.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('serial_number'))) {
            $this->merge(['serial_number' => DeviceSerial::normalize($this->input('serial_number')) ?? '']);
        }
    }

    /**
     * Cross-check that model_id belongs to make_id — but only when at least one
     * of them is being changed. The "other" side falls back to the device's
     * current value so changing just the model still validates against the
     * existing make (and vice-versa).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // LAUNCH-P1 P1-9: the round-up commission profile / organization
            // belong to the current assignment. A device in the pool has none,
            // so nothing can be pre-loaded to follow it to the next merchant.
            $device = $this->route('device');
            if ($device !== null && ($device->company_id === null || $device->branch_id === null)) {
                foreach (['commission_profile_id', 'organization_id'] as $field) {
                    if ($this->has($field)) {
                        $v->errors()->add($field, 'Assign the device to a merchant first; the round-up settings are chosen with the assignment.');
                    }
                }
            }
        });

        $validator->after(function (Validator $v): void {
            if (! $this->has('make_id') && ! $this->has('model_id')) {
                return;
            }

            $device = $this->route('device');
            $makeId = $this->has('make_id') ? $this->integer('make_id') : $device?->make_id;
            $modelId = $this->has('model_id') ? $this->integer('model_id') : $device?->model_id;
            if (! $makeId || ! $modelId) {
                return;
            }

            $belongs = DeviceModel::query()
                ->whereKey($modelId)
                ->where('make_id', $makeId)
                ->exists();

            if (! $belongs) {
                $v->errors()->add('model_id', 'The chosen model does not belong to the chosen make.');
            }
        });
    }
}
