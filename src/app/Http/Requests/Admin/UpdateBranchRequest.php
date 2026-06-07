<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\BranchOrderType;
use App\Enums\BranchStatus;
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
            'geofence_radius_m' => ['sometimes', 'integer', 'between:100,2000'],

            'opening_hours_json' => ['sometimes', 'nullable', 'array'],
            'opening_hours_json.*.open' => ['nullable', 'string', 'regex:/^[0-2]\d:[0-5]\d$/'],
            'opening_hours_json.*.close' => ['nullable', 'string', 'regex:/^[0-2]\d:[0-5]\d$/'],
            'opening_hours_json.*.closed' => ['nullable', 'boolean'],
            'default_order_type' => ['sometimes', Rule::enum(BranchOrderType::class)],

            'status' => ['sometimes', Rule::enum(BranchStatus::class)],
            'settings' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
