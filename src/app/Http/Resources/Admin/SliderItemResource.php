<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\MarketingSliderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MarketingSliderItem
 */
class SliderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'content_asset_id' => $this->content_asset_id,
            'advertiser_id' => $this->advertiser_id,
            'sort_order' => $this->sort_order,
            'duration_seconds' => $this->duration_seconds,
            'content' => $this->whenLoaded('contentAsset', fn (): ?array => $this->contentAsset === null ? null : [
                'id' => $this->contentAsset->id,
                'title' => $this->contentAsset->title,
                'type' => $this->contentAsset->type,
                'status' => $this->contentAsset->status,
                'url' => $this->contentAsset->public_url,
                'thumbnail_url' => $this->contentAsset->thumbnail_public_url,
                'duration_seconds' => $this->contentAsset->duration_seconds,
            ]),
            'advertiser' => $this->whenLoaded('advertiser', fn (): ?array => $this->advertiser === null ? null : [
                'id' => $this->advertiser->id,
                'brand_name' => $this->advertiser->brand_name,
            ]),
        ];
    }
}
