<?php

declare(strict_types=1);

namespace Database\Factories\Geo;

use App\Models\Geo\City;
use App\Models\Geo\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<City>
 */
class CityFactory extends Factory
{
    protected $model = City::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'region_id' => Region::factory(),
            'district_id' => null,
            'name' => 'City-'.fake()->unique()->bothify('####'),
            'postal_code' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
