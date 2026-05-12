<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Auth\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

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

it('rejects invalid credentials and rate limits repeated login attempts', function (): void {
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

    $this->postJson('/auth/login', [
        'email' => 'admin@example.test',
        'password' => 'wrong-password',
    ])->assertTooManyRequests();
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
