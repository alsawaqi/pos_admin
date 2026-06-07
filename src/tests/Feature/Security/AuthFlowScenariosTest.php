<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The 12 scenarios the user demanded must always pass
|--------------------------------------------------------------------------
|
| These exercise the public HTTP contract — the same contract a browser
| sees on direct URL entry, refresh, redirects, and full-page navigation.
| Browser-side back/forward cache is handled by the SPA's bfcacheGuard
| (unload listener + pageshow.persisted reload); the SERVER's job is to
| always return the correct response for the URL + cookie pair, and
| that's what we prove here.
|
| Each scenario walks through real /auth/login + /auth/logout requests
| so the cookie jar mirrors what a browser would carry.
*/

function loginViaForm($test): void
{
    User::factory()->create([
        'email' => 'flow-test@example.test',
        'password' => 'super-secret',
    ]);

    $test->post('/auth/login', [
        'email' => 'flow-test@example.test',
        'password' => 'super-secret',
    ])->assertRedirect('/admin');
}

it('1. logged-out user typing /admin is redirected to /login', function (): void {
    $this->get('/admin')->assertRedirect('/login');
});

it('2. logged-out user refreshing /admin is redirected to /login', function (): void {
    // A refresh is a plain GET with the same (absent) auth cookies.
    foreach (range(1, 3) as $refresh) {
        $this->get('/admin')->assertRedirect('/login');
    }
});

it('3. logged-out user pressing back to /admin must NOT receive a cacheable response', function (): void {
    // First, what a logged-in user would see — this is the response that
    // would be in the back/forward history. It must declare itself
    // uncacheable and keyed by Cookie so a guest browser cannot reuse it.
    $user = User::factory()->create();
    $authed = $this->actingAs($user)->get('/admin');

    expect($authed->headers->get('Cache-Control'))->toContain('no-store')
        ->and($authed->headers->get('Vary'))->toContain('Cookie');

    // Then the guest hit (what the back button would issue if bfcache is
    // bypassed) must redirect.
    Auth::logout();
    $this->get('/admin')->assertRedirect('/login');
});

it('4. logged-in user typing /admin sees the admin shell', function (): void {
    loginViaForm($this);

    $this->get('/admin')
        ->assertOk()
        ->assertSee('<div id="app">', false);
});

it('5. logged-in user refreshing /admin stays on /admin', function (): void {
    loginViaForm($this);

    foreach (range(1, 5) as $refresh) {
        $this->get('/admin')->assertOk();
    }
});

it('6. logged-in user typing /login is redirected to /admin', function (): void {
    loginViaForm($this);

    $this->get('/login')->assertRedirect('/admin');
});

it('7. logging in lands the user on /admin', function (): void {
    loginViaForm($this);

    expect(Auth::guard('web')->check())->toBeTrue();
});

it('8. after a fresh login the /login URL bounces back to /admin (back-button case)', function (): void {
    loginViaForm($this);

    // What a browser back button after login WOULD do, if it bypassed bfcache.
    $this->get('/login')->assertRedirect('/admin');
});

it('9. logging out redirects to /login and clears the session + JWT cookie', function (): void {
    loginViaForm($this);

    $this->post('/auth/logout')
        ->assertRedirect('/login')
        ->assertCookieExpired('pos_admin_jwt');

    $this->assertGuest('web');
});

it('10. after logout, typing /admin redirects to /login', function (): void {
    loginViaForm($this);

    $this->get('/admin')->assertOk();

    $this->post('/auth/logout')->assertRedirect('/login');

    $this->get('/admin')->assertRedirect('/login');
});

it('11. after logout, the /login response is uncacheable so the browser can never serve a stale /admin', function (): void {
    loginViaForm($this);

    // The authenticated /admin response must be uncacheable AND keyed by
    // Cookie, so the browser cannot reuse it after the session cookie has
    // changed (logout) — this is the structural guarantee that closes the
    // "back button after logout shows admin" hole.
    $authed = $this->get('/admin');
    expect($authed->headers->get('Cache-Control'))->toContain('no-store')
        ->and($authed->headers->get('Vary'))->toContain('Cookie');

    $this->post('/auth/logout');

    // The /login response after logout must also be no-store so the
    // browser cannot use it later as a stale guest landing page.
    $guest = $this->get('/login');
    expect($guest->headers->get('Cache-Control'))->toContain('no-store');
});

it('12. logging in again after logout works on the very first attempt', function (): void {
    loginViaForm($this);

    $this->post('/auth/logout')->assertRedirect('/login');
    expect(Auth::guard('web')->check())->toBeFalse();

    // No retry, no refresh — single shot must succeed.
    $this->post('/auth/login', [
        'email' => 'flow-test@example.test',
        'password' => 'super-secret',
    ])->assertRedirect('/admin');

    expect(Auth::guard('web')->check())->toBeTrue();
});
