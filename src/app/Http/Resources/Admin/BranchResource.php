<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Branch
 */
class BranchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'company_id' => $this->company_id,
            'company' => $this->whenLoaded('company', fn (): array => [
                'id' => $this->company->id,
                'uuid' => $this->company->uuid,
                'name' => $this->company->name,
                'name_ar' => $this->company->name_ar,
            ]),

            'name' => $this->name,
            'name_ar' => $this->name_ar,
            'code' => $this->code,

            'manager_name' => $this->manager_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,

            'country_id' => $this->country_id,
            'region_id' => $this->region_id,
            'district_id' => $this->district_id,
            'city_id' => $this->city_id,

            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'geofence_radius_m' => $this->geofence_radius_m,

            'opening_hours_json' => $this->opening_hours_json,
            'default_order_type' => $this->default_order_type?->value,

            'status' => $this->status?->value,
            'settings' => $this->settings,

            'devices_count' => $this->whenCounted('devices'),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
