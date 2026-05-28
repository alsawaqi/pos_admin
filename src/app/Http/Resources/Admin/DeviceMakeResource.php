<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\DeviceMake;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON projection of a {@see DeviceMake} for the Settings → Device
 * catalogue admin page and the Register Device cascading dropdown.
 *
 * @mixin DeviceMake
 */
class DeviceMakeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'display_order' => $this->display_order,
            'is_active' => $this->is_active,
            // Surfaced when the controller called withCount('models')
            // — used by the catalogue page's "N models" chip.
            'models_count' => $this->whenCounted('models'),
            // Same — used to block the Delete button on the UI when
            // the make is still in use by a device.
            'devices_count' => $this->whenCounted('devices'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
