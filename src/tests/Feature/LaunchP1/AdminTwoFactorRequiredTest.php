<?php

declare(strict_types=1);

/*
 * LAUNCH-P1 P1-15 (owner decision 2026-10-01): authenticator 2FA is
 * required for every admin; a Super Admin can reset a lost one. Plus
 * the low finding "admin logins and failed logins are not audited".
 */

require_once __DIR__.'/../../Support/launch-p1-helpers.php';

use App\Enums\PlatformRole;
use App\Models\User;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
});

function p1AdminWithoutTwoFactor(string $email = 'no2fa@mithqal.test'): User
{
    return p1Admin(PlatformRole::SuperAdmin->value, [
        'email' => $email,
        'password' => Hash::make('Admin-password-2026'),
        'two_factor_secret' => null,
        'two_factor_confirmed_at' => null,
    ]);
}

it('holds an admin without an authenticator on the setup page after login', function (): void {
    p1AdminWithoutTwoFactor();

    $this->postJson('/auth/login', ['email' => 'no2fa@mithqal.test', 'password' => 'Admin-password-2026'])
        ->assertOk()
        ->assertJsonPath('user.two_factor_setup_required', true);

    // Nothing else works until the authenticator is confirmed.
    $this->getJson('/admin/api/v1/merchants')
        ->assertStatus(403)
        ->assertJsonPath('code', 'two_factor_setup_required');
    $this->get('/admin/merchants')->assertRedirect('/admin/security?setup=required');

    // The setup page and the setup endpoints stay reachable.
    $this->get('/admin/security')->assertOk();
    $this->getJson('/auth/user')->assertOk()->assertJsonPath('user.two_factor_setup_required', true);
    $secret = (string) $this->postJson('/auth/two-factor')->assertOk()->json('secret');
    $this->postJson('/auth/two-factor/confirm', [
        'code' => (new Google2FA)->getCurrentOtp($secret),
    ])->assertOk();

    // Done: the admin portal opens up.
    $this->getJson('/admin/api/v1/merchants')->assertOk();
});

it('blocks an existing session of an admin without an authenticator', function (): void {
    $admin = p1AdminWithoutTwoFactor('old.session@mithqal.test');
    $this->actingAs($admin);

    $this->getJson('/admin/api/v1/platform-team')
        ->assertStatus(403)
        ->assertJsonPath('code', 'two_factor_setup_required');
    $this->getJson('/admin/api/v1/dashboard/summary')->assertStatus(403);
});

it('lets a Super Admin reset another admin\'s lost authenticator with a reason', function (): void {
    $super = p1ActingAs($this, PlatformRole::SuperAdmin->value);
    $target = p1Admin(PlatformRole::Support->value);
    $versionBefore = (int) DB::table('pos_users')->where('id', $target->id)->value('auth_version');

    // Confirmation and reason are mandatory.
    $this->postJson("/admin/api/v1/platform-team/{$target->id}/reset-two-factor", ['reason' => 'Lost phone'])
        ->assertStatus(422)->assertJsonValidationErrors(['confirm']);
    $this->postJson("/admin/api/v1/platform-team/{$target->id}/reset-two-factor", ['confirm' => true])
        ->assertStatus(422)->assertJsonValidationErrors(['reason']);

    $this->postJson("/admin/api/v1/platform-team/{$target->id}/reset-two-factor", [
        'confirm' => true,
        'reason' => 'Lost phone, verified by call',
    ])->assertOk()->assertJsonPath('data.two_factor_enabled', false);

    $fresh = DB::table('pos_users')->where('id', $target->id)->first();
    expect($fresh->two_factor_secret)->toBeNull()
        ->and($fresh->two_factor_confirmed_at)->toBeNull()
        ->and((int) $fresh->auth_version)->not->toBe($versionBefore);

    $audit = DB::table('pos_audit_logs')->where('event', 'platform_user.two_factor_reset_by_admin')->sole();
    expect((int) $audit->actor_user_id)->toBe($super->id)
        ->and((int) $audit->auditable_id)->toBe($target->id)
        ->and((string) $audit->metadata)->toContain('Lost phone, verified by call');
});

it('does not let a non-Super Admin reset someone\'s authenticator', function (): void {
    p1ActingAs($this, PlatformRole::OnboardingOfficer->value);
    $target = p1Admin(PlatformRole::Support->value);

    $this->postJson("/admin/api/v1/platform-team/{$target->id}/reset-two-factor", [
        'confirm' => true,
        'reason' => 'Trying anyway',
    ])->assertForbidden();

    expect(User::query()->find($target->id)?->hasConfirmedTwoFactor())->toBeTrue();
});

it('audits failed and successful admin logins without the password', function (): void {
    p1AdminWithoutTwoFactor('audited@mithqal.test');

    $this->postJson('/auth/login', ['email' => 'audited@mithqal.test', 'password' => 'Wrong-password-1'])
        ->assertStatus(422);
    $this->postJson('/auth/login', ['email' => 'ghost@mithqal.test', 'password' => 'Whatever-1'])
        ->assertStatus(422);
    $this->postJson('/auth/login', ['email' => 'audited@mithqal.test', 'password' => 'Admin-password-2026'])
        ->assertOk();

    $failed = DB::table('pos_audit_logs')->where('event', 'platform_user.login_failed')->orderBy('id')->get();
    expect($failed)->toHaveCount(2)
        ->and((string) $failed[0]->metadata)->toContain('wrong_password')
        ->and((string) $failed[1]->metadata)->toContain('unknown_email')
        ->and((string) $failed[0]->metadata)->not->toContain('Wrong-password-1');

    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'platform_user.login_succeeded']);
});

it('stores only a hash and a masked form of the typed email for an unknown login', function (): void {
    $typed = 'ghost.person@'.implode('.', array_fill(0, 10, 'very-long-domain')).'.example.test';

    $this->postJson('/auth/login', ['email' => $typed, 'password' => 'Whatever-1'])->assertStatus(422);

    $metadata = (string) DB::table('pos_audit_logs')->where('event', 'platform_user.login_failed')->sole()->metadata;
    $decoded = json_decode($metadata, true, flags: JSON_THROW_ON_ERROR);

    expect($metadata)->not->toContain('ghost.person')
        ->and($decoded['reason'])->toBe('unknown_email')
        ->and($decoded['email_hash'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($decoded['email_masked'])->toStartWith('g***@')
        ->and(mb_strlen($decoded['email_masked']))->toBeLessThanOrEqual(80)
        ->and($decoded)->not->toHaveKey('email');
});

it('audits a completed two-step admin login', function (): void {
    $admin = p1Admin(PlatformRole::Support->value, [
        'email' => 'twostep@mithqal.test',
        'password' => Hash::make('Admin-password-2026'),
    ]);

    $this->postJson('/auth/login', ['email' => 'twostep@mithqal.test', 'password' => 'Admin-password-2026'])
        ->assertOk()->assertJsonPath('two_factor', true);
    $this->postJson('/auth/two-factor-challenge', ['code' => p1TotpCode($admin)])->assertOk();

    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'platform_user.login_password_passed', 'auditable_id' => $admin->id]);
    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'platform_user.login_succeeded', 'auditable_id' => $admin->id]);
});
