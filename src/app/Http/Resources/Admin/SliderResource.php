<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\MarketingSlider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MarketingSlider
 */
class SliderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'loop_interval_seconds' => $this->loop_interval_seconds,
            'status' => $this->status,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'items_count' => $this->whenCounted('items'),
            'targets_count' => $this->whenCounted('targets'),
            'items' => SliderItemResource::collection($this->whenLoaded('items')),
            'targets' => SliderTargetResource::collection($this->whenLoaded('targets')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
