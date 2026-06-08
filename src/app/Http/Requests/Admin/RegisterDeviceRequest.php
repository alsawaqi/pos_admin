<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\DeviceType;
use App\Models\DeviceModel;
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

            // Commission profile from the shared charity DB. Must
            // reference an existing row (and ideally an active one,
            // but `is_active` filtering happens at the dropdown
            // source — we accept any existing id here so re-saves
            // of a device whose profile has since been deactivated
            // still work).
            'commission_profile_id' => [
                'required', 'integer',
                Rule::exists('commission_profiles', 'id'),
            ],

            // Beneficiary organization (shared charity DB) the device's card
            // round-up donations go to. Required, like commission_profile_id;
            // any existing id is accepted (active filtering is at the dropdown).
            'organization_id' => [
                'required', 'integer',
                Rule::exists('organizations', 'id'),
            ],

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

            // One of the three blueprint classes — Rule::enum keeps
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
