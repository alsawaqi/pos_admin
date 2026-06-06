<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\MerchantCommissionProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MerchantCommissionProfile
 */
class MerchantCommissionProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $shares = $this->shares->map(fn ($share): array => [
            'id' => $share->id,
            'party_type' => $share->party_type->value,
            'label' => $share->label,
            'percent' => (float) $share->percent,
            'sort_order' => $share->sort_order,
        ])->values();

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'exists' => $this->exists,
            'is_active' => (bool) $this->is_active,
            'merchant_percent' => (float) $this->merchant_percent,
            'total_share_percent' => round($shares->sum('percent'), 2),
            'shares' => $shares->all(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
