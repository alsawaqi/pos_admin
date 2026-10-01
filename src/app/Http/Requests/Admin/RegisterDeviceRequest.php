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
 * Validates the payload for POST /admin/api/v1/devices (Register Device
 * page, blueprint §4.4.2).
 *
 * Required: serial_number, kiosk_id, device_type.
 * Optional: name, label, model, app/firmware versions, metadata.
 *
 * Uniqueness on serial_number AND kiosk_id is enforced server-side
 * here AND at the database (unique indexes on pos_devices) so an
 * accidental retry can never create a duplicate row. The `Rule::unique`
 * intentionally does NOT scope by company because devices are
 * MITHQAL-owned, not merchant-owned — every device is unique across
 * the entire platform.
 */
class RegisterDeviceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Physical serial printed on the device, e.g. "POS-1234".
            // Globally unique. Soft-deleted devices keep the serial
            // claimed (no `whereNull('deleted_at')`) so a recycled
            // serial can't collide with an old record.
            'serial_number' => [
                'required', 'string', 'max:128',
                Rule::unique('pos_devices', 'serial_number'),
            ],

            // scalefusion kiosk id — REQUIRED. The POS app reads this
            // at first boot to call /device/pair. A device without one
            // can never come online.
            'kiosk_id' => [
                'required', 'string', 'max:128',
                Rule::unique('pos_devices', 'kiosk_id'),
            ],

            // LAUNCH-P1 P1-9: the round-up commission profile and beneficiary
            // organization are chosen at ASSIGN (with the merchant and branch),
            // never at registration, so they cannot follow a pooled device to
            // whichever merchant it is assigned to later.
            'commission_profile_id' => ['prohibited'],
            'organization_id' => ['prohibited'],

            // NOTE: terminal_id + bank_id are deliberately NOT captured at
            // registration. A registered device sits in the pool with no bank
            // terminal yet; both are set when the device is ASSIGNED to a
            // merchant (see AssignDeviceRequest) because the terminal_id is
            // issued against the merchant's bank account.

            // Display name + admin label are both free text.
            'name' => ['nullable', 'string', 'max:191'],
            'label' => ['nullable', 'string', 'max:128'],

            // Catalogue FKs — replaced the old free-text `model`
            // string. Both required; the cross-check that the model
            // actually belongs to the chosen make lives in
            // {@see withValidator()} below (a plain `exists` rule
            // can't express "this id is valid only when scoped to
            // that other id from the same payload").
            'make_id' => ['required', 'integer', Rule::exists('pos_device_makes', 'id')],
            'model_id' => ['required', 'integer', Rule::exists('pos_device_models', 'id')],

            // One of the supported device classes — Rule::enum keeps
            // the validation in sync with the DeviceType enum.
            'device_type' => ['required', Rule::enum(DeviceType::class)],

            // Versions are recorded for support but never required.
            'app_version' => ['nullable', 'string', 'max:64'],
            'firmware_version' => ['nullable', 'string', 'max:64'],

            // Free-form bag for anything scalefusion sends us that we
            // don't have a dedicated column for yet.
            'metadata' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'commission_profile_id.prohibited' => 'Choose the round-up commission profile when you assign the device to a merchant.',
            'organization_id.prohibited' => 'Choose the round-up organization when you assign the device to a merchant.',
        ];
    }

    /**
     * LAUNCH-P1 P1-12: the serial is stored normalised (trim, no whitespace,
     * upper case) so the unique rule — and pos_api's activation serial lock —
     * compare normalised values.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('serial_number'))) {
            $this->merge(['serial_number' => DeviceSerial::normalize($this->input('serial_number')) ?? '']);
        }
    }

    /**
     * Cross-check that `model_id` is actually a child of `make_id`.
     * Without this, an admin could submit a Sunmi make paired with
     * a PAX model id and the schema alone wouldn't catch it. We
     * could express this with a fancy `exists` callback but a clear
     * after-validator hook reads better.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $makeId = $this->integer('make_id');
            $modelId = $this->integer('model_id');
            if (! $makeId || ! $modelId) {
                return; // base `required` rules will have surfaced
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
