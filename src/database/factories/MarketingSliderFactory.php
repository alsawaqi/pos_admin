<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MarketingSlider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MarketingSlider>
 */
class MarketingSliderFactory extends Factory
{
    protected $model = MarketingSlider::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'name' => ucfirst(fake()->unique()->words(2, true)).' Slider',
            'loop_interval_seconds' => 8,
            'status' => 'draft',
            'created_by_user_id' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => 'active']);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => ['status' => 'paused']);
    }
}
