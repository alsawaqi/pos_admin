<?php

declare(strict_types=1);

/*
 * LAUNCH-P1 P1-8 — admins get forgot-password (email link) + reset page,
 * a change-password form, and are invited with a set-password link
 * instead of a given password. All audited.
 */

require_once __DIR__.'/../../Support/launch-p1-helpers.php';

use App\Enums\PlatformRole;
use App\Enums\UserType;
use App\Mail\SetPasswordLinkMail;
use App\Models\User;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
    config(['app.url' => 'https://posadmin.mithqal.test']);
});

/**
 * @return array{token: string, email: string, path: string}
 */
function p1AdminLink(string $url): array
{
    $parts = parse_url($url);
    parse_str($parts['query'] ?? '', $query);

    return [
        'token' => (string) ($query['token'] ?? ''),
        'email' => (string) ($query['email'] ?? ''),
        'path' => (string) ($parts['path'] ?? ''),
    ];
}

it('invites an admin with a set-password link instead of a given password', function (): void {
    p1ActingAs($this, PlatformRole::SuperAdmin->value);

    $response = $this->postJson('/admin/api/v1/platform-team', [
        'name' => 'New Admin',
        'email' => 'new.admin@mithqal.test',
        'role' => PlatformRole::Support->value,
    ])->assertCreated()
        ->assertJsonMissingPath('plaintext_password')
        ->assertJsonPath('set_password_link.purpose', 'invite')
        ->assertJsonPath('data.password_set', false);

    $link = p1AdminLink((string) $response->json('set_password_link.url'));
    expect($link['path'])->toBe('/set-password')
        ->and(str_starts_with((string) $response->json('set_password_link.url'), 'https://posadmin.mithqal.test/'))->toBeTrue();

    $invited = User::query()->where('email', 'new.admin@mithqal.test')->firstOrFail();
    expect($invited->password)->toBeNull()
        ->and($invited->user_type)->toBe(UserType::PlatformAdmin);
});

it('lets an invited admin choose a password through the link, once', function (): void {
    p1ActingAs($this, PlatformRole::SuperAdmin->value);
    $response = $this->postJson('/admin/api/v1/platform-team', [
        'name' => 'Linked Admin',
        'email' => 'linked@mithqal.test',
        'role' => PlatformRole::Support->value,
    ])->assertCreated();
    $link = p1AdminLink((string) $response->json('set_password_link.url'));
    $this->postJson('/auth/logout');
    auth()->forgetGuards();

    $this->postJson('/auth/reset-password', [
        'email' => $link['email'],
        'token' => $link['token'],
        'password' => 'Chosen-by-me-2026',
        'password_confirmation' => 'Chosen-by-me-2026',
    ])->assertOk();

    $user = User::query()->where('email', 'linked@mithqal.test')->firstOrFail();
    expect(Hash::check('Chosen-by-me-2026', (string) $user->password))->toBeTrue();
    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'platform_user.password_set', 'auditable_id' => $user->id]);

    // Single use.
    $this->postJson('/auth/reset-password', [
        'email' => $link['email'],
        'token' => $link['token'],
        'password' => 'Another-choice-2026',
        'password_confirmation' => 'Another-choice-2026',
    ])->assertStatus(422)->assertJsonValidationErrors(['token']);
});

it('refuses a weak admin password on the set-password page', function (): void {
    $admin = p1Admin(PlatformRole::Support->value, ['password' => null]);
    DB::table('pos_password_reset_tokens')->insert([
        'user_id' => $admin->id,
        'token_hash' => hash('sha256', str_repeat('a', 64)),
        'purpose' => 'invite',
        'expires_at' => now()->addDay(),
        'created_at' => now(),
    ]);

    $this->postJson('/auth/reset-password', [
        'email' => $admin->email,
        'token' => str_repeat('a', 64),
        'password' => 'short1',
        'password_confirmation' => 'short1',
    ])->assertStatus(422)->assertJsonValidationErrors(['password']);
});

it('sends a forgot-password link to an active admin and answers the same for unknown emails', function (): void {
    config(['mail.default' => 'smtp']);
    Mail::fake();
    $admin = p1Admin(PlatformRole::Support->value, ['email' => 'forgetful@mithqal.test']);
    $merchant = User::factory()->merchant()->create(['email' => 'merchant@cafe.test']);

    $known = $this->postJson('/auth/forgot-password', ['email' => 'forgetful@mithqal.test'])->assertOk();
    $unknown = $this->postJson('/auth/forgot-password', ['email' => 'nobody@mithqal.test'])->assertOk();
    $this->postJson('/auth/forgot-password', ['email' => 'merchant@cafe.test'])->assertOk();

    expect($known->json('message'))->toBe($unknown->json('message'));
    Mail::assertSent(SetPasswordLinkMail::class, fn (SetPasswordLinkMail $mail): bool => $mail->hasTo('forgetful@mithqal.test')
        && $mail->purpose === 'forgot'
        && str_contains($mail->url, '/reset-password?'));
    Mail::assertSent(SetPasswordLinkMail::class, 1);

    $row = DB::table('pos_password_reset_tokens')->where('user_id', $admin->id)->sole();
    expect($row->purpose)->toBe('forgot');
    expect(DB::table('pos_password_reset_tokens')->where('user_id', $merchant->id)->exists())->toBeFalse();
});

it('resets an admin password from the forgot-password email and ends old sessions', function (): void {
    config(['mail.default' => 'smtp']);
    Mail::fake();
    $admin = p1Admin(PlatformRole::Support->value, ['email' => 'resetme@mithqal.test']);
    $versionBefore = (int) DB::table('pos_users')->where('id', $admin->id)->value('auth_version');

    $this->postJson('/auth/forgot-password', ['email' => 'resetme@mithqal.test'])->assertOk();
    $url = null;
    Mail::assertSent(SetPasswordLinkMail::class, function (SetPasswordLinkMail $mail) use (&$url): bool {
        $url = $mail->url;

        return true;
    });
    $link = p1AdminLink((string) $url);

    $this->postJson('/auth/reset-password', [
        'email' => 'resetme@mithqal.test',
        'token' => $link['token'],
        'password' => 'Brand-new-secret-77',
        'password_confirmation' => 'Brand-new-secret-77',
    ])->assertOk();

    $fresh = DB::table('pos_users')->where('id', $admin->id)->first();
    expect(Hash::check('Brand-new-secret-77', (string) $fresh->password))->toBeTrue()
        ->and((int) $fresh->auth_version)->not->toBe($versionBefore);
});

it('answers a wrong or expired reset token with one generic message', function (): void {
    $admin = p1Admin(PlatformRole::Support->value);
    DB::table('pos_password_reset_tokens')->insert([
        'user_id' => $admin->id,
        'token_hash' => hash('sha256', str_repeat('e', 64)),
        'purpose' => 'forgot',
        'expires_at' => now()->subMinute(),
        'created_at' => now()->subHour(),
    ]);

    foreach ([str_repeat('e', 64), str_repeat('x', 64)] as $token) {
        $this->postJson('/auth/reset-password', [
            'email' => $admin->email,
            'token' => $token,
            'password' => 'Valid-password-2026',
            'password_confirmation' => 'Valid-password-2026',
        ])->assertStatus(422)->assertJsonValidationErrors(['token']);
    }
});

it('lets a signed-in admin change their own password, ending only the other sessions', function (): void {
    $admin = p1ActingAs($this, PlatformRole::Support->value, ['password' => Hash::make('Current-password-1')]);

    $this->postJson('/auth/change-password', [
        'current_password' => 'wrong-password',
        'password' => 'Next-password-2026',
        'password_confirmation' => 'Next-password-2026',
    ])->assertStatus(422)->assertJsonValidationErrors(['current_password']);

    $versionBefore = (int) DB::table('pos_users')->where('id', $admin->id)->value('auth_version');

    $this->postJson('/auth/change-password', [
        'current_password' => 'Current-password-1',
        'password' => 'Next-password-2026',
        'password_confirmation' => 'Next-password-2026',
    ])->assertOk();

    $fresh = DB::table('pos_users')->where('id', $admin->id)->first();
    expect(Hash::check('Next-password-2026', (string) $fresh->password))->toBeTrue()
        ->and((int) $fresh->auth_version)->not->toBe($versionBefore);

    // This session survives the rotation.
    $this->getJson('/auth/user')->assertOk();
    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'platform_user.password_changed', 'auditable_id' => $admin->id]);
});

it('blocks a reset admin\'s old password at once and tells them to use the link', function (): void {
    p1ActingAs($this, PlatformRole::SuperAdmin->value);
    $target = p1Admin(PlatformRole::Support->value, [
        'email' => 'reset.target@mithqal.test',
        'password' => Hash::make('Old-password-2026'),
    ]);
    $versionBefore = (int) DB::table('pos_users')->where('id', $target->id)->value('auth_version');

    $response = $this->postJson("/admin/api/v1/platform-team/{$target->id}/set-password-link")
        ->assertOk()
        ->assertJsonPath('set_password_link.purpose', 'reset');
    $link = p1AdminLink((string) $response->json('set_password_link.url'));

    $fresh = DB::table('pos_users')->where('id', $target->id)->first();
    expect($fresh->password)->toBeNull()
        ->and((int) $fresh->auth_version)->not->toBe($versionBefore);

    // A different browser: the old password no longer signs in, and
    // the message points to the link instead of "wrong password".
    $this->postJson('/auth/logout');
    auth()->forgetGuards();
    $message = (string) $this->postJson('/auth/login', ['email' => 'reset.target@mithqal.test', 'password' => 'Old-password-2026'])
        ->assertStatus(422)
        ->json('errors.email.0');
    expect($message)->toContain('set-password link');
    $this->assertGuest('web');
    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'platform_user.login_failed', 'auditable_id' => $target->id]);

    // Only the link works.
    $this->postJson('/auth/reset-password', [
        'email' => 'reset.target@mithqal.test',
        'token' => $link['token'],
        'password' => 'Brand-new-2026-pass',
        'password_confirmation' => 'Brand-new-2026-pass',
    ])->assertOk();
    $this->postJson('/auth/login', ['email' => 'reset.target@mithqal.test', 'password' => 'Brand-new-2026-pass'])
        ->assertOk()
        ->assertJsonPath('two_factor', true);
});

it('resends an admin invite link from the Team page', function (): void {
    p1ActingAs($this, PlatformRole::SuperAdmin->value);
    $pending = p1Admin(PlatformRole::Support->value, ['password' => null]);

    $this->postJson("/admin/api/v1/platform-team/{$pending->id}/set-password-link")
        ->assertOk()
        ->assertJsonPath('set_password_link.purpose', 'invite');

    expect(DB::table('pos_password_reset_tokens')->where('user_id', $pending->id)->whereNull('used_at')->count())->toBe(1);
});
