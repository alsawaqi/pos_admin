<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\ContentAsset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContentAsset
 */
class MarketingContentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type,
            'status' => $this->status,
            'advertiser_id' => $this->advertiser_id,
            'advertiser' => $this->whenLoaded('advertiser', fn (): ?array => $this->advertiser === null ? null : [
                'id' => $this->advertiser->id,
                'brand_name' => $this->advertiser->brand_name,
                'name' => $this->advertiser->name,
            ]),
            'url' => $this->public_url,
            'thumbnail_url' => $this->thumbnail_public_url,
            'duration_seconds' => $this->duration_seconds,
            'width' => $this->width,
            'height' => $this->height,
            'review_note' => $this->review_note,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
