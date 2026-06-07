<?php

declare(strict_types=1);

namespace Database\Factories\Geo;

use App\Models\Geo\Country;
use App\Models\Geo\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Region>
 */
class RegionFactory extends Factory
{
    protected $model = Region::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'country_id' => Country::factory(),
            'name' => 'Region-'.fake()->unique()->bothify('####'),
            'type' => 'governorate',
            'code' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
