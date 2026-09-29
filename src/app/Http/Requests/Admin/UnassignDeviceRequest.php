<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the payload for POST /admin/api/v1/devices/{device}/unassign.
 *
 * The only field is the optional `reason` string, which gets recorded
 * on the now-closing assignment history row for audit purposes
 * (e.g. "branch closed", "device damaged", "reallocated to HQ").
 */
class UnassignDeviceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'override_reason' => ['nullable', 'string', 'min:3', 'max:1000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
