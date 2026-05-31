<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Geo\Region;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Region
 */
class RegionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'country_id' => $this->country_id,
            'name' => $this->name,
            'type' => $this->type,
            'code' => $this->code,
            'is_active' => (bool) $this->is_active,
            'districts_count' => $this->whenCounted('districts'),
            'cities_count' => $this->whenCounted('cities'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
