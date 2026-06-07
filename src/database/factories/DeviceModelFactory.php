<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DeviceMake;
use App\Models\DeviceModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test factory for {@see DeviceModel}. make_id falls back to a
 * fresh DeviceMake when callers don't pin it down via ->for().
 *
 * @extends Factory<DeviceModel>
 */
class DeviceModelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Auto-create a make if the test didn't ->for() one in.
            'make_id' => DeviceMake::factory(),

            // Unique within (make_id, name) — `bothify` gives us
            // enough entropy to avoid collisions across factory
            // calls during a single test.
            'name' => 'Model-'.fake()->unique()->bothify('####??'),

            'code' => null,
            'display_order' => 0,
            'is_active' => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
