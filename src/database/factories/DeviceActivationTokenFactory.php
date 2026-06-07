<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Device;
use App\Models\DeviceActivationToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DeviceActivationToken>
 */
class DeviceActivationTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'token_hash' => hash('sha256', Str::random(64)),
            'expires_at' => now()->addMinutes(30),
        ];
    }
}
