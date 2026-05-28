<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the payload for POST /admin/api/v1/devices/{device}/assign
 * (Assign Device page, blueprint §4.4.3).
 *
 * Both company_id and branch_id are required AND we cross-check that
 * the branch actually belongs to the company in the controller layer
 * (the `exists` rules alone would let an admin paste any valid branch
 * id from another tenant). The geo-fence radius override is optional
 * — if omitted, the device inherits whatever radius is already set on
 * the branch row.
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

            // Same bounds the branch form uses (blueprint §4.3.2).
            // The action will write this back to the branch row when
            // present so the override sticks for every future device
            // assigned to the same branch.
            'geofence_radius_m' => ['nullable', 'integer', 'between:100,2000'],
        ];
    }
}
