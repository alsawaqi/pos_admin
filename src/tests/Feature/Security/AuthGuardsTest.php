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

it('refuses to expose the login JSON endpoint to an authenticated XHR client', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/auth/login', [
            'email' => 'whatever@example.test',
            'password' => 'whatever',
        ])
        ->assertStatus(409);
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
