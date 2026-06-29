<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MarketingSlider;
use App\Models\MarketingSliderTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketingSliderTarget>
 */
class MarketingSliderTargetFactory extends Factory
{
    protected $model = MarketingSliderTarget::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slider_id' => MarketingSlider::factory(),
            'branch_id' => null,
            'device_id' => null,
        ];
    }
}
