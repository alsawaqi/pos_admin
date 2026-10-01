<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\BusinessActivitySelection;
use Illuminate\Contracts\Validation\Validator;
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
            'activities.*.business_activity_id' => ['required', 'integer', 'distinct', 'exists:pos_business_activities,id'],
            'activities.*.is_primary' => ['nullable', 'boolean'],
        ];
    }

    /**
     * LAUNCH-P1 P1-18: exactly one primary activity (two primaries used
     * to reach the action and surface as a 500).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if (! BusinessActivitySelection::hasExactlyOnePrimary($this->input('activities'))) {
                $v->errors()->add('activities', BusinessActivitySelection::PRIMARY_MESSAGE);
            }
        });
    }
}
