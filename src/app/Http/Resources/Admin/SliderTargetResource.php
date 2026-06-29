<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\MarketingSliderTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MarketingSliderTarget
 */
class SliderTargetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'device_id' => $this->device_id,
            'branch' => $this->whenLoaded('branch', fn (): ?array => $this->branch === null ? null : [
                'id' => $this->branch->id,
                'uuid' => $this->branch->uuid,
                'name' => $this->branch->name,
            ]),
            'device' => $this->whenLoaded('device', fn (): ?array => $this->device === null ? null : [
                'id' => $this->device->id,
                'uuid' => $this->device->uuid,
                'name' => $this->device->name ?? $this->device->label,
            ]),
        ];
    }
}
