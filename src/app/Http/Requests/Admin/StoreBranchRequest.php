<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\BranchOrderType;
use App\Enums\BranchStatus;
use App\Support\BranchRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->input('company_id');

        return [
            'company_id' => ['required', 'integer', 'exists:pos_companies,id'],
            'name' => ['required', 'string', 'max:191'],
            'name_ar' => ['nullable', 'string', 'max:191'],
            'code' => [
                'nullable', 'string', 'max:64',
                // Code is unique only within a company — same code is fine across tenants.
                Rule::unique('pos_branches', 'code')
                    ->where(fn ($q) => $q->where('company_id', $companyId))
                    ->whereNull('deleted_at'),
            ],

            'manager_name' => ['nullable', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:191'],
            'address' => ['nullable', 'string', 'max:500'],

            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'region_id' => ['nullable', 'integer', 'exists:regions,id'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],

            // Geo + fence — blueprint §4.3.2 / §9.4.
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'geofence_radius_m' => ['nullable', 'integer', BranchRules::radiusRule()],

            // Operations.
            'opening_hours_json' => ['nullable', 'array'],
            // Times + "close after open" are checked in withValidator.
            'opening_hours_json.*.open' => ['nullable', 'string'],
            'opening_hours_json.*.close' => ['nullable', 'string'],
            'opening_hours_json.*.closed' => ['nullable', 'boolean'],
            'default_order_type' => ['nullable', Rule::enum(BranchOrderType::class)],

            'status' => ['nullable', Rule::enum(BranchStatus::class)],
            'settings' => ['nullable', 'array'],
        ];
    }

    /**
     * LAUNCH-P1 P1-17: explicit location (never the untouched map pin)
     * and real opening hours.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            BranchRules::validateLocation($v, $this->input('latitude'), $this->input('longitude'));
            BranchRules::validateOpeningHours($v, $this->input('opening_hours_json'));
        });
    }
}
