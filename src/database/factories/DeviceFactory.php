<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DeviceStatus;
use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'serial_number' => strtoupper(fake()->bothify('POS-####-????')),
            'name' => 'POS Terminal',
            'device_type' => 'pos_terminal',
            'status' => DeviceStatus::Registered,
            'metadata' => [],
        ];
    }
}
