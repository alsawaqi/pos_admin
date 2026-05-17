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
    putenv('VITE_DEV_SERVER_URL=http://localhost:5174');

    $csp = (string) $this->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain('http://localhost:5174')
        ->and($csp)->toContain('script-src-elem')
        ->and($csp)->toContain('style-src-elem')
        ->and($csp)->toContain('ws://localhost:5174');
});
