<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\DeviceModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON projection of a {@see DeviceModel}. Always carries the
 * make_id (so the catalogue page can group + filter without a
 * follow-up request); the parent make's name is included only when
 * preloaded.
 *
 * @mixin DeviceModel
 */
class DeviceModelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'make_id' => $this->make_id,
            'name' => $this->name,
            'code' => $this->code,
            'display_order' => $this->display_order,
            'is_active' => $this->is_active,
            // Only present when the controller preloaded the relation
            // (e.g. on the Register Device form which renders
            // "Sunmi / P2 Mini" labels in a single list).
            'make' => $this->whenLoaded('make', fn (): array => [
                'id' => $this->make->id,
                'name' => $this->make->name,
            ]),
            'devices_count' => $this->whenCounted('devices'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
