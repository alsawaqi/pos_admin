<?php

declare(strict_types=1);

namespace Database\Factories\Geo;

use App\Models\Geo\District;
use App\Models\Geo\Region;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<District>
 */
class DistrictFactory extends Factory
{
    protected $model = District::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'region_id' => Region::factory(),
            'name' => 'District-'.fake()->unique()->bothify('####'),
        ];
    }
}
