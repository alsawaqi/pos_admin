<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ContentAsset;
use App\Models\MarketingSlider;
use App\Models\MarketingSliderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketingSliderItem>
 */
class MarketingSliderItemFactory extends Factory
{
    protected $model = MarketingSliderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slider_id' => MarketingSlider::factory(),
            'content_asset_id' => ContentAsset::factory()->status('approved'),
            'advertiser_id' => null,
            'sort_order' => 0,
            'duration_seconds' => 8,
        ];
    }
}
