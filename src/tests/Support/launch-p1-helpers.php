<?php

declare(strict_types=1);

/*
 * Shared fixtures for the LAUNCH-P1 part A regression tests.
 *
 * Everything here uses only the public factories / HTTP surface that
 * already existed on the Phase 0 base, so each LaunchP1 test file can be
 * replayed against a clean export of the base commit (the
 * "fails before, passes after" proof).
 */

use App\Models\User;
use App\Support\Auth\TwoFactorAuth;
use App\Support\TenantContext;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

if (! function_exists('p1Admin')) {
    /**
     * A platform admin with the given platform role and a CONFIRMED
     * authenticator (P1-15: every admin must have one, so a realistic
     * admin fixture carries it).
     *
     * @param  array<string, mixed>  $attributes
     */
    function p1Admin(string $role, array $attributes = []): User
    {
        /** @var User $user */
        $user = User::factory()->create(array_merge([
            'two_factor_secret' => app(TwoFactorAuth::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ], $attributes));

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
        $user->assignRole($role);

        return $user->fresh() ?? $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    function p1ActingAs(TestCase $test, string $role, array $attributes = []): User
    {
        $user = p1Admin($role, $attributes);
        $test->actingAs($user);
        $test->withSession(['pos.auth_version' => (int) $user->auth_version]);

        return $user;
    }

    /**
     * The current valid TOTP code for a user's stored secret.
     */
    function p1TotpCode(User $user): string
    {
        return (new Google2FA)->getCurrentOtp((string) $user->fresh()?->two_factor_secret);
    }
}
