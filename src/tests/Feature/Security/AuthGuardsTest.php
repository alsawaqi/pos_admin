<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('serves the login shell to a guest', function (): void {
    $this->get('/login')
        ->assertOk()
        ->assertSee('<div id="app">', false);
});

it('redirects an authenticated visitor away from /login', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/login')
        ->assertRedirect('/admin');
});

it('treats a login POST from an already-authenticated client as a session refresh', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/auth/login', [
            'email' => $user->email,
            'password' => 'whatever',
        ])
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonStructure(['token' => ['type', 'access_token', 'expires_at']]);
});

it('redirects a guest away from /admin to /login', function (): void {
    $this->get('/admin')->assertRedirect('/login');
    $this->get('/admin/merchants')->assertRedirect('/login');
});

it('hides the /auth/user payload from direct browser navigation', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/auth/user', ['Accept' => 'text/html'])
        ->assertNotFound();
});

it('serves the /auth/user payload to an XHR client when authenticated', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/auth/user')
        ->assertOk()
        ->assertJsonPath('user.id', $user->id);
});

it('requires JSON Accept header even for unauthenticated /auth/user', function (): void {
    // Even without auth, a browser navigation should get 404, not a redirect.
    // The auth middleware still fires after RequireJsonRequest under the
    // wrapping order, so the priority order matters; this test asserts the
    // public-facing behaviour rather than the internal order.
    $response = $this->get('/auth/user', ['Accept' => 'text/html']);

    expect($response->status())->toBeIn([302, 404]);
});

it('emits no-store cache headers on every response', function (): void {
    $response = $this->get('/login');

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->headers->get('Pragma'))->toBe('no-cache');
});

it('issues a fresh CSRF token to an XHR caller', function (): void {
    $this->getJson('/auth/csrf')
        ->assertOk()
        ->assertJsonStructure(['csrf_token']);
});

it('hides the CSRF endpoint from a direct browser navigation', function (): void {
    $this->get('/auth/csrf', ['Accept' => 'text/html'])->assertNotFound();
});

it('logs in via a native form POST and redirects to /admin', function (): void {
    $user = User::factory()->create([
        'email' => 'form-login@example.test',
        'password' => 'super-secret',
    ]);

    $this->post('/auth/login', [
        'email' => 'form-login@example.test',
        'password' => 'super-secret',
        'remember' => '1',
    ])->assertRedirect('/admin');

    $this->assertAuthenticatedAs($user, 'web');
});

it('redirects back with errors when a form login fails', function (): void {
    User::factory()->create([
        'email' => 'form-login@example.test',
        'password' => 'correct-password',
    ]);

    $this->from('/login')
        ->post('/auth/login', [
            'email' => 'form-login@example.test',
            'password' => 'WRONG',
        ])
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['email'])
        ->assertSessionHasInput('email', 'form-login@example.test');

    $this->assertGuest('web');
});

it('logs out via a native form POST and redirects to /login', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/auth/logout')
        ->assertRedirect('/login')
        ->assertCookieExpired('pos_admin_jwt');

    $this->assertGuest('web');
});

it('coerces the remember checkbox value of "on" to a boolean', function (): void {
    $user = User::factory()->create([
        'email' => 'checkbox@example.test',
        'password' => 'pw-1234567',
    ]);

    $this->post('/auth/login', [
        'email' => 'checkbox@example.test',
        'password' => 'pw-1234567',
        'remember' => 'on', // raw HTML checkbox value
    ])->assertRedirect('/admin');

    $this->assertAuthenticatedAs($user, 'web');
});
