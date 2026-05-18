<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Auth\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    RateLimiter::clear('admin@example.test|127.0.0.1');
});

it('logs in a valid admin, returns a JWT, and stores remember-me intent', function (): void {
    $user = User::factory()->create([
        'email' => 'admin@example.test',
        'password' => 'secret-password',
        'remember_token' => null,
    ]);

    $response = $this->postJson('/auth/login', [
        'email' => 'admin@example.test',
        'password' => 'secret-password',
        'remember' => true,
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('session.remembered', true)
        ->assertJsonStructure([
            'token' => [
                'type',
                'access_token',
                'expires_at',
            ],
        ])
        ->assertCookie('pos_admin_jwt')
        ->assertCookie(Auth::guard('web')->getRecallerName());

    $token = (string) $response->json('token.access_token');
    $payload = app(JwtTokenService::class)->decode($token);

    expect(explode('.', $token))->toHaveCount(3)
        ->and($payload['sub'] ?? null)->toBe((string) $user->id)
        ->and($payload['guard'] ?? null)->toBe('web')
        ->and(session('pos_admin.remembered'))->toBeTrue();
});

it('rejects invalid credentials and rate limits after repeated failures', function (): void {
    User::factory()->create([
        'email' => 'admin@example.test',
        'password' => 'correct-password',
    ]);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/auth/login', [
            'email' => 'admin@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    // 6th failed attempt is the one that trips the lockout. The controller
    // surfaces the throttle as a 422 with the auth.throttle message rather
    // than a bare 429 because the limit lives inside the form request now.
    $response = $this->postJson('/auth/login', [
        'email' => 'admin@example.test',
        'password' => 'wrong-password',
    ])->assertStatus(422)->assertJsonValidationErrors(['email']);

    expect((string) $response->json('errors.email.0'))->toContain('seconds');
});

it('does not consume the rate limit when login succeeds', function (): void {
    User::factory()->create([
        'email' => 'admin@example.test',
        'password' => 'correct-password',
    ]);

    // A burst of successful logins (10x the failure limit) all return OK
    // because the counter is cleared on every successful attempt.
    foreach (range(1, 10) as $attempt) {
        $this->postJson('/auth/login', [
            'email' => 'admin@example.test',
            'password' => 'correct-password',
        ])->assertOk();
    }
});

it('clears the rate limit when a successful login follows failed attempts', function (): void {
    User::factory()->create([
        'email' => 'admin@example.test',
        'password' => 'correct-password',
    ]);

    foreach (range(1, 4) as $attempt) {
        $this->postJson('/auth/login', [
            'email' => 'admin@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    // The good password lands within the window — counter resets.
    $this->postJson('/auth/login', [
        'email' => 'admin@example.test',
        'password' => 'correct-password',
    ])->assertOk();

    // We can immediately consume the full failure quota again, proving
    // the previous bad attempts no longer count against us.
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/auth/login', [
            'email' => 'admin@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }
});

it('expires non-remembered authenticated sessions after the idle timeout', function (): void {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->withSession([
            'pos_admin.remembered' => false,
            'pos_admin.last_activity_at' => now()->subMinutes(31)->timestamp,
        ])
        ->getJson('/auth/user')
        ->assertUnauthorized();

    $this->assertGuest('web');
});

it('keeps remembered authenticated sessions alive past the idle timeout', function (): void {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->withSession([
            'pos_admin.remembered' => true,
            'pos_admin.last_activity_at' => now()->subMinutes(31)->timestamp,
        ])
        ->getJson('/auth/user')
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('session.remembered', true);
});

it('logs out an authenticated admin and clears the JWT cookie', function (): void {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->postJson('/auth/logout')
        ->assertNoContent()
        ->assertCookieExpired('pos_admin_jwt');

    $this->assertGuest('web');
});
