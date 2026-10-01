<?php

declare(strict_types=1);

/*
 * LAUNCH-P1 P1-2 (set-password link instead of a hand-given password),
 * P1-14 (first login without a device), P1-3 (mail by configuration,
 * never breaking the request) and the low finding "an admin password
 * reset for a merchant user does not end their other sessions".
 */

require_once __DIR__.'/../../Support/launch-p1-helpers.php';

use App\Enums\PlatformRole;
use App\Enums\UserType;
use App\Mail\SetPasswordLinkMail;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
    config(['app.merchant_portal_url' => 'https://posmerchant.mithqal.test']);
});

/**
 * @return array{token: string, email: string, path: string, host: string}
 */
function p1ParseLink(string $url): array
{
    $parts = parse_url($url);
    parse_str($parts['query'] ?? '', $query);

    return [
        'token' => (string) ($query['token'] ?? ''),
        'email' => (string) ($query['email'] ?? ''),
        'path' => (string) ($parts['path'] ?? ''),
        'host' => (string) ($parts['host'] ?? ''),
    ];
}

it('creates a portal login with a 72-hour set-password link and no password at all', function (): void {
    $admin = p1ActingAs($this, PlatformRole::OnboardingOfficer->value);
    $company = Company::factory()->create();

    $response = $this->postJson("/admin/api/v1/merchants/{$company->uuid}/portal-users", [
        'name' => 'Aisha Owner',
        'email' => 'aisha@cafe.test',
    ])->assertCreated();

    $response->assertJsonMissingPath('plaintext_password')
        ->assertJsonPath('data.setup_pending', true)
        ->assertJsonPath('data.password_set', false)
        ->assertJsonPath('set_password_link.purpose', 'invite');

    $link = p1ParseLink((string) $response->json('set_password_link.url'));
    expect($link['host'])->toBe('posmerchant.mithqal.test')
        ->and($link['path'])->toBe('/setup-password')
        ->and($link['email'])->toBe('aisha@cafe.test')
        ->and(strlen($link['token']))->toBe(64);

    $user = User::query()->where('email', 'aisha@cafe.test')->firstOrFail();
    expect($user->password)->toBeNull()
        ->and($user->user_type)->toBe(UserType::Merchant)
        ->and($user->invited_by_admin_id)->toBe($admin->id);

    // Only the hash is stored, single row, 72 hours, invite purpose.
    $row = DB::table('pos_password_reset_tokens')->where('user_id', $user->id)->sole();
    expect($row->token_hash)->toBe(hash('sha256', $link['token']))
        ->and($row->token_hash)->not->toBe($link['token'])
        ->and($row->purpose)->toBe('invite')
        ->and($row->used_at)->toBeNull();
    expect(now()->diffInMinutes(Carbon::parse($row->expires_at)))->toBeGreaterThan(71 * 60);

    // The audit trail never carries the token or the link.
    $audit = DB::table('pos_audit_logs')->where('event', 'portal_user.set_password_link_issued')->sole();
    expect((string) $audit->new_values)->not->toContain($link['token']);
    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'portal_user.created', 'auditable_id' => $user->id]);
});

it('gives the first login before the merchant has any branch or device', function (): void {
    p1ActingAs($this, PlatformRole::OnboardingOfficer->value);
    $company = Company::factory()->create(); // onboarding, no branch, no device

    $this->postJson("/admin/api/v1/merchants/{$company->uuid}/portal-users", [
        'name' => 'Early Owner',
        'email' => 'early@cafe.test',
    ])->assertCreated();

    expect(User::query()->where('email', 'early@cafe.test')->exists())->toBeTrue();
});

it('emails the link when mail is configured', function (): void {
    config(['mail.default' => 'smtp']);
    Mail::fake();
    p1ActingAs($this, PlatformRole::OnboardingOfficer->value);
    $company = Company::factory()->create(['name' => 'Phase One Cafe']);

    $response = $this->postJson("/admin/api/v1/merchants/{$company->uuid}/portal-users", [
        'name' => 'Mailed Owner',
        'email' => 'mailed@cafe.test',
    ])->assertCreated()
        ->assertJsonPath('set_password_link.emailed', true);

    $url = (string) $response->json('set_password_link.url');
    Mail::assertSent(SetPasswordLinkMail::class, fn (SetPasswordLinkMail $mail): bool => $mail->hasTo('mailed@cafe.test')
        && $mail->url === $url
        && $mail->purpose === 'invite'
        && $mail->companyName === 'Phase One Cafe');
});

it('does not mail a live link into the log when no mail transport is configured', function (): void {
    config(['mail.default' => 'log']);
    Mail::fake();
    p1ActingAs($this, PlatformRole::OnboardingOfficer->value);
    $company = Company::factory()->create();

    $this->postJson("/admin/api/v1/merchants/{$company->uuid}/portal-users", [
        'name' => 'Copy Link Owner',
        'email' => 'copy@cafe.test',
    ])->assertCreated()
        ->assertJsonPath('set_password_link.emailed', false)
        ->assertJsonPath('set_password_link.mail_configured', false);

    Mail::assertNothingSent();
});

it('still creates the login and returns the link when the mail server is down', function (): void {
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => '127.0.0.1',
        'mail.mailers.smtp.port' => 9,
        'mail.mailers.smtp.timeout' => 2,
    ]);
    p1ActingAs($this, PlatformRole::OnboardingOfficer->value);
    $company = Company::factory()->create();

    $this->postJson("/admin/api/v1/merchants/{$company->uuid}/portal-users", [
        'name' => 'Offline Mail Owner',
        'email' => 'offline@cafe.test',
    ])->assertCreated()
        ->assertJsonPath('set_password_link.emailed', false)
        ->assertJsonPath('set_password_link.mail_configured', true)
        ->assertJson(fn ($json) => $json->where('set_password_link.email_error', fn ($v) => is_string($v) && $v !== '')->etc());

    expect(User::query()->where('email', 'offline@cafe.test')->exists())->toBeTrue();
});

it('resends an invite as a new 72-hour link and kills the older one', function (): void {
    p1ActingAs($this, PlatformRole::OnboardingOfficer->value);
    $company = Company::factory()->create();

    $first = $this->postJson("/admin/api/v1/merchants/{$company->uuid}/portal-users", [
        'name' => 'Resend Owner',
        'email' => 'resend@cafe.test',
    ])->assertCreated();
    $userId = (int) $first->json('data.id');
    $firstToken = p1ParseLink((string) $first->json('set_password_link.url'))['token'];

    $second = $this->postJson("/admin/api/v1/merchants/{$company->uuid}/portal-users/{$userId}/reset-password")
        ->assertOk()
        ->assertJsonMissingPath('plaintext_password')
        ->assertJsonPath('set_password_link.purpose', 'invite');
    $secondToken = p1ParseLink((string) $second->json('set_password_link.url'))['token'];

    expect($secondToken)->not->toBe($firstToken);
    $rows = DB::table('pos_password_reset_tokens')->where('user_id', $userId)->whereNull('used_at')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->token_hash)->toBe(hash('sha256', $secondToken));
});

it('resets a merchant password with a 60-minute link and ends that user\'s sessions', function (): void {
    p1ActingAs($this, PlatformRole::OnboardingOfficer->value);
    $company = Company::factory()->create();
    $merchantUser = User::factory()->merchant()->create([
        'company_id' => $company->id,
        'password' => Hash::make('Their-own-password-1'),
        'remember_token' => 'remember-me-token',
    ]);
    $versionBefore = (int) DB::table('pos_users')->where('id', $merchantUser->id)->value('auth_version');

    $response = $this->postJson("/admin/api/v1/merchants/{$company->uuid}/portal-users/{$merchantUser->id}/reset-password")
        ->assertOk()
        ->assertJsonMissingPath('plaintext_password')
        ->assertJsonPath('set_password_link.purpose', 'reset');

    $link = p1ParseLink((string) $response->json('set_password_link.url'));
    expect($link['path'])->toBe('/reset-password');

    $row = DB::table('pos_password_reset_tokens')->where('user_id', $merchantUser->id)->sole();
    expect($row->purpose)->toBe('reset');
    $minutes = now()->diffInMinutes(Carbon::parse($row->expires_at));
    expect($minutes)->toBeGreaterThan(58)->toBeLessThanOrEqual(60);

    $fresh = DB::table('pos_users')->where('id', $merchantUser->id)->first();
    expect((int) $fresh->auth_version)->not->toBe($versionBefore)
        ->and($fresh->remember_token)->toBeNull()
        // No admin-chosen password replaced the merchant's own.
        ->and(Hash::check('Their-own-password-1', (string) $fresh->password))->toBeTrue();

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'portal_user.password_reset',
        'auditable_id' => $merchantUser->id,
    ]);
});

it('never exposes a token or token hash in the portal users list', function (): void {
    p1ActingAs($this, PlatformRole::OnboardingOfficer->value);
    $company = Company::factory()->create();

    $created = $this->postJson("/admin/api/v1/merchants/{$company->uuid}/portal-users", [
        'name' => 'Listed Owner',
        'email' => 'listed@cafe.test',
    ])->assertCreated();
    $token = p1ParseLink((string) $created->json('set_password_link.url'))['token'];

    $list = $this->getJson("/admin/api/v1/merchants/{$company->uuid}/portal-users")
        ->assertOk()
        ->assertJsonPath('data.0.setup_pending', true);

    expect($list->json('data.0.set_password_link_expires_at'))->toBeString();
    expect($list->getContent())->not->toContain($token)
        ->not->toContain(hash('sha256', $token));
});
