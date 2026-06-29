<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Advertiser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Advertiser
 */
class AdvertiserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'brand_name' => $this->brand_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'is_merchant' => (bool) $this->is_merchant,
            'company_id' => $this->company_id,
            'company' => $this->whenLoaded('company', fn (): ?array => $this->company === null ? null : [
                'id' => $this->company->id,
                'uuid' => $this->company->uuid,
                'name' => $this->company->name,
            ]),
            'category' => $this->category,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
