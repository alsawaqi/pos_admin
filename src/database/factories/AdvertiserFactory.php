<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Advertiser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<Advertiser>
 */
class AdvertiserFactory extends Factory
{
    protected $model = Advertiser::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'brand_name' => fake()->company(),
            'email' => fake()->unique()->safeEmail(),
            // Already-hashed; the model's `hashed` cast leaves a bcrypt hash as-is.
            'password' => Hash::make('password'),
            'phone' => fake()->optional()->numerify('+9665########'),
            'status' => 'active',
            'company_id' => null,
            'is_merchant' => false,
            'category' => null,
            'remember_token' => Str::random(10),
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => 'suspended']);
    }
}
