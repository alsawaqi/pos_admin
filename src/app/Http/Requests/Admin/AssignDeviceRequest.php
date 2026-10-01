<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Actions\Admin\ReserveMerchantTerminalAction;
use App\Enums\DeviceType;
use App\Models\Device;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the payload for POST /admin/api/v1/devices/{device}/assign
 * (Assign Device page, blueprint §4.4.3).
 *
 * Assignment binds the device to a (company, branch) AND captures its
 * soft-POS terminal: bank_id + terminal_id (moved here from registration —
 * the terminal is issued against the merchant's bank account, so it is only
 * known at assign time).
 *
 * company_id + branch_id are required (the branch↔company cross-check lives
 * in AssignDeviceAction). terminal_id is required and unique PER BANK — the
 * same terminal_id may exist under a different bank, never twice under the
 * same one; uniqueness compares NORMALISED values (trim, no whitespace, upper
 * case — the same rule as the merchant terminal reservation). A customer
 * tablet may be assigned without a bank terminal (LAUNCH-P1 low).
 *
 * LAUNCH-P1 P1-9: the round-up commission profile + beneficiary organization
 * are chosen HERE, with the merchant and branch. They are required whenever
 * the device gets a new (company, branch); a re-save on the same branch (for
 * example a bank terminal edit) keeps the current ones when they are omitted.
 * A customer tablet (no payments) may be assigned without them.
 *
 * LAUNCH-P1 2a: location_mode 'branch' (default for a new assignment) or
 * 'any'. 'branch' needs a branch with coordinates (checked in the action).
 *
 * The geo-fence radius override is optional — if omitted, the device
 * inherits whatever radius is already set on the branch row.
 */
class AssignDeviceRequest extends FormRequest
{
    public const TERMINAL_TAKEN = 'This bank terminal is already reserved by another device, including disabled or archived devices. Disable that device and release its bank terminal before reusing it.';

    /**
     * @return array<string, mixed>
     */
    public function messages(): array
    {
        return [
            'terminal_id.unique' => self::TERMINAL_TAKEN,
            'commission_profile_id.required' => 'Choose the round-up commission profile for this assignment.',
            'organization_id.required' => 'Choose the round-up organization for this assignment.',
        ];
    }

    public function rules(): array
    {
        /** @var Device|null $device */
        $device = $this->route('device');
        // A customer tablet takes no payments: no terminal and no round-up
        // settings are required for it (owner decision 2026-10-01).
        $terminalOptional = $device?->device_type === DeviceType::CustomerTablet;
        $donationRequired = ! $terminalOptional && ($device === null
            || (int) $device->company_id !== $this->integer('company_id')
            || (int) $device->branch_id !== $this->integer('branch_id'));

        return [
            'terminal_transfer_reason' => ['nullable', 'string', 'min:3', 'max:1000'],
            'override_reason' => ['nullable', 'string', 'min:3', 'max:1000'],
            'company_id' => ['required', 'integer', 'exists:pos_companies,id'],
            'branch_id' => ['required', 'integer', 'exists:pos_branches,id'],

            // Acquiring bank that issued the terminal. Any existing id is
            // accepted (active or not) so a re-assign doesn't break when a
            // bank was deactivated on the charity side.
            'bank_id' => [$terminalOptional ? 'nullable' : 'required', 'required_with:terminal_id', 'integer', Rule::exists('banks', 'id')],

            // Bank-issued terminal identifier. Unique WITHIN the chosen bank
            // (not globally), including soft-deleted devices, comparing the
            // normalised value. Ignore this device so a re-save keeps its own.
            'terminal_id' => [
                $terminalOptional ? 'nullable' : 'required', 'required_with:bank_id', 'string', 'max:64',
                function (string $attribute, mixed $value, Closure $fail) use ($device): void {
                    $bankId = $this->integer('bank_id');
                    if (! is_string($value) || $bankId === 0) {
                        return;
                    }
                    $normal = ReserveMerchantTerminalAction::normalize($value);
                    $taken = Device::withTrashed()->where('bank_id', $bankId)->whereNotNull('terminal_id')
                        ->when($device !== null, fn ($query) => $query->whereKeyNot($device->id))
                        ->pluck('terminal_id')
                        ->contains(fn ($terminal): bool => ReserveMerchantTerminalAction::normalize((string) $terminal) === $normal);
                    if ($taken) {
                        $fail(self::TERMINAL_TAKEN);
                    }
                },
            ],

            // Per-device Mosambee Soft-POS login PIN — OPTIONAL,
            // issued by the bank alongside the terminal_id. Empty /
            // whitespace-only input is normalised to NULL (the
            // ConvertEmptyStringsToNull middleware handles '', the
            // Action trims the rest) so the device falls back to the
            // vendor default PIN.
            'terminal_pin' => ['nullable', 'string', 'max:32'],
            'use_default_pin' => ['sometimes', 'boolean'],

            // Round-up settings of THIS assignment (shared charity DB ids; any
            // existing id is accepted, active filtering is at the dropdown).
            'commission_profile_id' => [$donationRequired ? 'required' : 'sometimes', 'integer', Rule::exists('commission_profiles', 'id')],
            'organization_id' => [$donationRequired ? 'required' : 'sometimes', 'integer', Rule::exists('organizations', 'id')],

            'location_mode' => ['sometimes', 'string', Rule::in(['branch', 'any'])],

            // Blueprint §4.4.3 bounds: 500–2000 m, the same as branches.
            // The action will write this back to the branch row when
            // present so the override sticks for every future device
            // assigned to the same branch.
            'geofence_radius_m' => ['nullable', 'integer', 'between:500,2000'],
        ];
    }
}
