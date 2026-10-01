<?php

namespace Database\Factories;

use App\Enums\UserType;
use App\Models\User;
use App\Support\Auth\TwoFactorAuth;
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
            // LAUNCH-P1 P1-15: every admin must have a confirmed
            // authenticator, so a realistic admin row carries one. Use
            // withoutTwoFactor() for an admin who has not set it up.
            'two_factor_secret' => static fn (array $attributes) => self::isMerchantRow($attributes)
                ? null
                : app(TwoFactorAuth::class)->generateSecret(),
            'two_factor_confirmed_at' => static fn (array $attributes) => self::isMerchantRow($attributes)
                ? null
                : now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function isMerchantRow(array $attributes): bool
    {
        $type = $attributes['user_type'] ?? null;

        return $type === UserType::Merchant || $type === UserType::Merchant->value;
    }

    /**
     * An admin who has not set up two-step login yet (P1-15: such an
     * admin is held on the setup page).
     */
    public function withoutTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
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
