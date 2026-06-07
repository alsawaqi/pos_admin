<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

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
 * same one. The geo-fence radius override is optional — if omitted, the
 * device inherits whatever radius is already set on the branch row.
 */
class AssignDeviceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'exists:pos_companies,id'],
            'branch_id' => ['required', 'integer', 'exists:pos_branches,id'],

            // Acquiring bank that issued the terminal. Any existing id is
            // accepted (active or not) so a re-assign doesn't break when a
            // bank was deactivated on the charity side.
            'bank_id' => ['required', 'integer', Rule::exists('banks', 'id')],

            // Bank-issued terminal identifier. Unique WITHIN the chosen bank
            // (not globally) — scoped by bank_id, ignoring soft-deleted
            // (decommissioned) devices and this device itself so a re-save
            // keeps its own terminal.
            'terminal_id' => [
                'required', 'string', 'max:64',
                Rule::unique('pos_devices', 'terminal_id')
                    ->where(fn ($query) => $query
                        ->where('bank_id', $this->integer('bank_id'))
                        ->whereNull('deleted_at'))
                    ->ignore($this->route('device')?->id),
            ],

            // Same bounds the branch form uses (blueprint §4.3.2).
            // The action will write this back to the branch row when
            // present so the override sticks for every future device
            // assigned to the same branch.
            'geofence_radius_m' => ['nullable', 'integer', 'between:100,2000'],
        ];
    }
}
