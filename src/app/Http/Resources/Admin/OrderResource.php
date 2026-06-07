<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Read-only projection of an {@see Order} for the platform-wide
 * Sales / Orders viewer. Carries the owning merchant (company) + branch
 * so the admin table can show "which merchant".
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'uuid' => $this->uuid,
            'company' => $this->whenLoaded('company', fn (): ?array => $this->company === null ? null : [
                'uuid' => $this->company->uuid,
                'name' => $this->company->name,
                'name_ar' => $this->company->name_ar,
            ]),
            'branch' => $this->whenLoaded('branch', fn (): ?array => $this->branch === null ? null : [
                'uuid' => $this->branch->uuid,
                'name' => $this->branch->name,
            ]),
            'order_type' => $this->order_type?->value,
            'status' => $this->status?->value,
            'source' => $this->source?->value,
            'grand_total' => (string) $this->grand_total,
            'opened_at' => $this->opened_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
        ];
    }
}
