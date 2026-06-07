<?php

declare(strict_types=1);

it('applies baseline security headers to public responses', function (): void {
    $response = $this->get('/login');

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
    expect($response->headers->get('Content-Security-Policy'))->toContain("default-src 'self'");
    expect($response->headers->get('Permissions-Policy'))->toContain('camera=()');
});

it('omits HSTS for non-HTTPS requests', function (): void {
    $response = $this->get('/login');

    expect($response->headers->has('Strict-Transport-Security'))->toBeFalse();
});

it('whitelists the Vite dev server origin in script/style/connect-src during local dev', function (): void {
    config()->set('app.env', 'local');

    // Read whatever VITE_DEV_SERVER_URL the environment is currently
    // wired with — Laravel's env() helper caches on first read so calling
    // putenv() here would not actually override what the middleware sees.
    // pos_admin currently runs the Vite container on host port 5175 to
    // avoid colliding with pos_merchant on 5174 (see docker-compose.yml).
    $viteOrigin = (string) env('VITE_DEV_SERVER_URL', 'http://localhost:5175');
    $wsOrigin = 'ws://'.substr($viteOrigin, strlen('http://'));

    $csp = (string) $this->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain($viteOrigin)
        ->and($csp)->toContain('script-src-elem')
        ->and($csp)->toContain('style-src-elem')
        ->and($csp)->toContain($wsOrigin);
});
