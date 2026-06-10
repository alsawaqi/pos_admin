<?php

declare(strict_types=1);

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

/**
 * Phase D8 — opt-in TOTP two-factor auth for platform admins
 * (blueprint §4.1.1 "Optional two-factor authentication via TOTP").
 *
 * The admin-specific stake: the login response carries a JWT cookie.
 * For an enrolled account the password step must yield NEITHER a
 * session NOR the JWT — only the challenge endpoint issues both.
 */

/** Current 6-digit TOTP for a base32 secret, via the same RFC 6238 engine. */
function currentTotp(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

/**
 * Create a platform admin, walk the full enrolment as them, then
 * sign out so login tests start from a clean guest session.
 *
 * @return array{user: User, secret: string, recoveryCodes: list<string>}
 */
function makeEnrolledAdmin(string $email = 'admin@example.test'): array
{
    $user = User::factory()->create(['email' => $email]);

    test()->actingAs($user);

    $secret = (string) test()->postJson('/auth/two-factor')->assertOk()->json('secret');
    $confirm = test()->postJson('/auth/two-factor/confirm', [
        'code' => currentTotp($secret),
    ])->assertOk();

    test()->postJson('/auth/logout')->assertNoContent();

    return [
        'user' => $user->fresh(),
        'secret' => $secret,
        'recoveryCodes' => $confirm->json('recovery_codes'),
    ];
}

// ---------------------------------------------------------------
// Enrolment
// ---------------------------------------------------------------

it('starts enrolment with a QR + secret and stores the secret encrypted', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->postJson('/auth/two-factor')->assertOk();

    $secret = (string) $response->json('secret');
    expect($secret)->toMatch('/^[A-Z2-7]{32}$/'); // base32, 160 bits
    expect((string) $response->json('otpauth_url'))
        ->toContain('otpauth://totp/')
        ->toContain('secret='.$secret);
    expect((string) $response->json('svg'))->toStartWith('<svg');

    // Encrypted at rest: the raw column is ciphertext, not the secret.
    $raw = (string) DB::table('pos_users')->where('id', $user->id)->value('two_factor_secret');
    expect($raw)->not->toBe($secret);
    expect(Crypt::decryptString($raw))->toBe($secret);

    // Unconfirmed — login is NOT yet gated.
    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'platform_user.two_factor_setup_started',
        'actor_user_id' => $user->id,
    ]);
});

it('enables 2FA only after a valid code and hands out hashed-at-rest recovery codes', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $secret = (string) $this->postJson('/auth/two-factor')->assertOk()->json('secret');

    // Wrong code first — still disabled.
    $wrong = currentTotp($secret) === '000000' ? '000001' : '000000';
    $this->postJson('/auth/two-factor/confirm', ['code' => $wrong])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);
    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();

    // Valid code — enabled + 8 one-time recovery codes.
    $response = $this->postJson('/auth/two-factor/confirm', ['code' => currentTotp($secret)])
        ->assertOk()
        ->assertJsonPath('two_factor_enabled', true);

    $codes = $response->json('recovery_codes');
    expect($codes)->toHaveCount(8);
    expect($codes[0])->toMatch('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/');

    $fresh = $user->fresh();
    expect($fresh->two_factor_confirmed_at)->not->toBeNull();

    // Stored values are SHA-256 hashes of the issued codes — never plaintext.
    $stored = $fresh->two_factor_recovery_codes;
    expect($stored)->toHaveCount(8);
    foreach ($codes as $i => $plain) {
        $canonical = strtoupper(str_replace('-', '', $plain));
        expect($stored[$i])->toBe(hash('sha256', $canonical));
    }

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'platform_user.two_factor_enabled',
        'actor_user_id' => $user->id,
    ]);
});

it('refuses to start a new enrolment while 2FA is already enabled', function (): void {
    ['user' => $user] = makeEnrolledAdmin();
    $this->actingAs($user);

    $this->postJson('/auth/two-factor')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['two_factor']);
});

it('requires authentication for every enrolment endpoint', function (): void {
    $this->postJson('/auth/two-factor')->assertStatus(401);
    $this->postJson('/auth/two-factor/confirm', ['code' => '123456'])->assertStatus(401);
    $this->deleteJson('/auth/two-factor', ['current_password' => 'x', 'code' => '123456'])->assertStatus(401);
});

// ---------------------------------------------------------------
// Login challenge — the JWT must be gated too
// ---------------------------------------------------------------

it('does not authenticate an enrolled admin on password alone — no session, NO JWT cookie', function (): void {
    ['user' => $user] = makeEnrolledAdmin();

    $response = $this->postJson('/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertOk()
        ->assertExactJson(['two_factor' => true])
        ->assertCookieMissing('pos_admin_jwt');

    expect($response->json('token'))->toBeNull();
    $this->assertGuest('web');
    $this->getJson('/auth/user')->assertStatus(401);
});

it('redirects a form (non-JSON) login to the challenge page without a JWT', function (): void {
    ['user' => $user] = makeEnrolledAdmin();

    $this->post('/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertRedirect('/two-factor-challenge')
        ->assertCookieMissing('pos_admin_jwt');

    $this->assertGuest('web');
});

it('completes the login with a valid TOTP code — session AND JWT issued here', function (): void {
    ['user' => $user, 'secret' => $secret] = makeEnrolledAdmin();

    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk();

    $this->postJson('/auth/two-factor-challenge', ['code' => currentTotp($secret)])
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.two_factor_enabled', true)
        ->assertJsonStructure(['token' => ['type', 'access_token', 'expires_at']])
        ->assertCookie('pos_admin_jwt');

    $this->assertAuthenticatedAs($user, 'web');

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'platform_user.two_factor_challenge_passed',
        'actor_user_id' => $user->id,
    ]);
});

it('rejects an invalid challenge code, audits it, and stays guest', function (): void {
    ['user' => $user, 'secret' => $secret] = makeEnrolledAdmin();

    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk();

    $wrong = currentTotp($secret) === '000000' ? '000001' : '000000';
    $this->postJson('/auth/two-factor-challenge', ['code' => $wrong])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code'])
        ->assertCookieMissing('pos_admin_jwt');

    $this->assertGuest('web');

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'platform_user.two_factor_challenge_failed',
        'actor_user_id' => $user->id,
    ]);
});

it('throttles repeated challenge failures', function (): void {
    ['user' => $user, 'secret' => $secret] = makeEnrolledAdmin();

    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk();

    $max = (int) config('pos_admin_auth.rate_limits.two_factor_per_minute', 5);
    $wrong = currentTotp($secret) === '000000' ? '000001' : '000000';

    foreach (range(1, $max) as $i) {
        $this->postJson('/auth/two-factor-challenge', ['code' => $wrong])
            ->assertStatus(422);
    }

    $response = $this->postJson('/auth/two-factor-challenge', ['code' => $wrong])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);

    expect((string) $response->json('errors.code.0'))->toContain('seconds');
    $this->assertGuest('web');
});

it('accepts a recovery code exactly once', function (): void {
    ['user' => $user, 'recoveryCodes' => $codes] = makeEnrolledAdmin();

    $burned = $codes[0];

    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk();

    $this->postJson('/auth/two-factor-challenge', ['recovery_code' => $burned])
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertCookie('pos_admin_jwt');
    $this->assertAuthenticatedAs($user, 'web');

    // Burned — 7 hashes remain.
    expect($user->fresh()->two_factor_recovery_codes)->toHaveCount(7);

    // Second use of the SAME code from a fresh login fails.
    $this->postJson('/auth/logout')->assertNoContent();
    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk();

    $this->postJson('/auth/two-factor-challenge', ['recovery_code' => $burned])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);
    $this->assertGuest('web');
});

it('rejects a challenge without any pending login state', function (): void {
    makeEnrolledAdmin();

    $this->postJson('/auth/two-factor-challenge', ['code' => '123456'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['challenge']);

    $this->assertGuest('web');
});

it('expires the pending challenge after its TTL', function (): void {
    ['user' => $user, 'secret' => $secret] = makeEnrolledAdmin();

    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk();

    $ttl = (int) config('pos_admin_auth.two_factor.challenge_ttl_minutes', 5);
    $this->travel($ttl + 1)->minutes();

    $this->postJson('/auth/two-factor-challenge', ['code' => currentTotp($secret)])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['challenge']);

    $this->assertGuest('web');
});

it('reports challenge-pending state to the SPA page', function (): void {
    ['user' => $user] = makeEnrolledAdmin();

    $this->getJson('/auth/two-factor-challenge')
        ->assertOk()
        ->assertJsonPath('pending', false);

    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk();

    $this->getJson('/auth/two-factor-challenge')
        ->assertOk()
        ->assertJsonPath('pending', true);
});

it('refuses to complete a challenge for a row that stopped being a platform admin', function (): void {
    // Cross-portal gate: park a legitimate pending state, then flip
    // the row to merchant (e.g. an admin-side type change landing
    // mid-challenge). The platformAdmin()-scoped pending lookup must
    // refuse to convert it into an ADMIN session.
    ['user' => $user, 'secret' => $secret] = makeEnrolledAdmin();

    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk()
        ->assertExactJson(['two_factor' => true]);

    $user->forceFill(['user_type' => UserType::Merchant])->save();

    $this->postJson('/auth/two-factor-challenge', ['code' => currentTotp($secret)])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['challenge'])
        ->assertCookieMissing('pos_admin_jwt');

    $this->assertGuest('web');
});

it('still logs a non-enrolled admin straight in with the JWT (no challenge)', function (): void {
    $user = User::factory()->create(['email' => 'plain@example.test']);

    $this->postJson('/auth/login', ['email' => 'plain@example.test', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.two_factor_enabled', false)
        ->assertCookie('pos_admin_jwt');

    $this->assertAuthenticatedAs($user, 'web');
});

// ---------------------------------------------------------------
// Disable
// ---------------------------------------------------------------

it('disables 2FA with password + live code, returning login to a single step', function (): void {
    ['user' => $user, 'secret' => $secret] = makeEnrolledAdmin();
    $this->actingAs($user);

    $this->deleteJson('/auth/two-factor', [
        'current_password' => 'password',
        'code' => currentTotp($secret),
    ])
        ->assertOk()
        ->assertJsonPath('two_factor_enabled', false);

    $fresh = $user->fresh();
    expect($fresh->two_factor_secret)->toBeNull();
    expect($fresh->two_factor_recovery_codes)->toBeNull();
    expect($fresh->two_factor_confirmed_at)->toBeNull();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'platform_user.two_factor_disabled',
        'actor_user_id' => $user->id,
    ]);

    // Plain login works again — no challenge step.
    $this->postJson('/auth/logout')->assertNoContent();
    $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertCookie('pos_admin_jwt');
    $this->assertAuthenticatedAs($user, 'web');
});

it('refuses to disable 2FA with a wrong password even when the code is valid', function (): void {
    ['user' => $user, 'secret' => $secret] = makeEnrolledAdmin();
    $this->actingAs($user);

    $this->deleteJson('/auth/two-factor', [
        'current_password' => 'not-the-password',
        'code' => currentTotp($secret),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['current_password']);

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

it('refuses to disable 2FA with a wrong code even when the password is valid', function (): void {
    ['user' => $user, 'secret' => $secret] = makeEnrolledAdmin();
    $this->actingAs($user);

    $wrong = currentTotp($secret) === '000000' ? '000001' : '000000';
    $this->deleteJson('/auth/two-factor', [
        'current_password' => 'password',
        'code' => $wrong,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

it('accepts a recovery code as the second factor when disabling', function (): void {
    ['user' => $user, 'recoveryCodes' => $codes] = makeEnrolledAdmin();
    $this->actingAs($user);

    $this->deleteJson('/auth/two-factor', [
        'current_password' => 'password',
        'recovery_code' => $codes[3],
    ])
        ->assertOk()
        ->assertJsonPath('two_factor_enabled', false);

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();
});
