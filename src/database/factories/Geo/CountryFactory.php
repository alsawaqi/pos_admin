<?php

declare(strict_types=1);

namespace Database\Factories\Geo;

use App\Models\Geo\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Country>
 */
class CountryFactory extends Factory
{
    protected $model = Country::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Country-'.fake()->unique()->bothify('####'),
            'iso_code' => strtoupper(fake()->unique()->lexify('??')),
            'phone_code' => '+'.fake()->numberBetween(1, 998),
            'is_active' => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
