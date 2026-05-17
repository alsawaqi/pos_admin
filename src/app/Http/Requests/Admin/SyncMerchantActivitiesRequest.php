<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SyncMerchantActivitiesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'activities' => ['required', 'array', 'min:1'],
            'activities.*.business_activity_id' => ['required', 'integer', 'exists:pos_admin_business_activities,id'],
            'activities.*.is_primary' => ['nullable', 'boolean'],
        ];
    }
}
