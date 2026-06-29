<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Advertiser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full advertiser detail for the admin detail page — the advertiser account, its
 * linked company (reusing {@see CompanyDetailResource}; editable only when
 * `is_advertiser_only`), and a content-status summary. Mirrors the merchant
 * detail payload so the Vue Show page can render the same tabbed layout.
 *
 * @mixin Advertiser
 */
class AdvertiserDetailResource extends JsonResource
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
            'category' => $this->category,
            'created_at' => $this->created_at?->toIso8601String(),

            // The linked company (advertising-only or a real merchant). Loaded
            // with owners + activities so the Company / Owners / Activities tabs
            // can edit it. Null for a standalone advertiser.
            'company' => $this->whenLoaded('company', fn (): ?array => $this->company === null
                ? null
                : (new CompanyDetailResource($this->company))->toArray($request)),

            // Content-status summary for the Overview tab (set by the controller).
            'content_stats' => $this->content_stats ?? null,
        ];
    }
}
