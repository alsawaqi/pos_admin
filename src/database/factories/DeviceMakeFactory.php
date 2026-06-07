<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DeviceMake;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test factory for {@see DeviceMake}. Name has a unique constraint
 * so we suffix with a random hex blob to avoid collisions when a
 * single test creates several makes in parallel.
 *
 * @extends Factory<DeviceMake>
 */
class DeviceMakeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Make-'.fake()->unique()->bothify('####??'),
            'display_order' => 0,
            'is_active' => true,
        ];
    }

    /**
     * Convenience state for tests that need a deactivated make
     * (e.g. "this entry shouldn't appear in the dropdown anymore").
     */
    public function inactive(): self
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
