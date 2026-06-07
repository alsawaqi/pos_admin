<?php

namespace Database\Factories;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 *
 * Default state inside the pos_admin app is `platform_admin` so
 * `User::factory()->create()` in this codebase produces an admin
 * user — which is what every feature test was relying on before
 * the user_type gate landed in the auth pipeline. The shared
 * `pos_users` table's column default is `merchant` (chosen by the
 * original migration for pos_merchant ergonomics), so without an
 * explicit override here the new login + EnsureUserIsAuthenticated
 * gates would refuse every factory-built user. Tests that
 * specifically need a merchant row use the `->merchant()` state.
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'user_type' => UserType::PlatformAdmin,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Build a merchant-side user row. Use this in tests that need
     * to prove the pos_admin auth pipeline rejects merchant
     * credentials, or that need a merchant teammate fixture before
     * exercising the cross-tenant / cross-app paths.
     */
    public function merchant(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_type' => UserType::Merchant,
        ]);
    }
}
