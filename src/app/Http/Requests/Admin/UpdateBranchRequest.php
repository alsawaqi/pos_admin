<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\BranchOrderType;
use App\Enums\BranchStatus;
use App\Support\BranchRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $branch = $this->route('branch');
        $branchId = $branch?->id;
        $companyId = $branch?->company_id;

        return [
            'name' => ['sometimes', 'string', 'max:191'],
            'name_ar' => ['sometimes', 'nullable', 'string', 'max:191'],
            'code' => [
                'sometimes', 'nullable', 'string', 'max:64',
                Rule::unique('pos_branches', 'code')
                    ->where(fn ($q) => $q->where('company_id', $companyId))
                    ->ignore($branchId)
                    ->whereNull('deleted_at'),
            ],

            'manager_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:191'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],

            'country_id' => ['sometimes', 'nullable', 'integer', 'exists:countries,id'],
            'region_id' => ['sometimes', 'nullable', 'integer', 'exists:regions,id'],
            'district_id' => ['sometimes', 'nullable', 'integer', 'exists:districts,id'],
            'city_id' => ['sometimes', 'nullable', 'integer', 'exists:cities,id'],

            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'geofence_radius_m' => ['sometimes', 'integer', BranchRules::radiusRule()],

            'opening_hours_json' => ['sometimes', 'nullable', 'array'],
            // Times + "close after open" are checked in withValidator.
            'opening_hours_json.*.open' => ['nullable', 'string'],
            'opening_hours_json.*.close' => ['nullable', 'string'],
            'opening_hours_json.*.closed' => ['nullable', 'boolean'],
            'default_order_type' => ['sometimes', Rule::enum(BranchOrderType::class)],

            'status' => ['sometimes', Rule::enum(BranchStatus::class)],
            'settings' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * LAUNCH-P1 P1-17: a location change must be explicit (never the
     * untouched map pin) and opening hours must be real. A branch that
     * already sits on the old default pin can still be edited for other
     * fields; only MOVING it onto that pin is refused.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $branch = $this->route('branch');

            if ($this->has('latitude') || $this->has('longitude')) {
                $latitude = $this->input('latitude', $branch?->latitude);
                $longitude = $this->input('longitude', $branch?->longitude);
                $unchanged = $branch !== null
                    && BranchRules::isUnsetMapPin($branch->latitude, $branch->longitude);

                if (! $unchanged) {
                    BranchRules::validateLocation($v, $latitude, $longitude);
                }
            }

            if ($this->has('opening_hours_json')) {
                BranchRules::validateOpeningHours($v, $this->input('opening_hours_json'));
            }
        });
    }
}
