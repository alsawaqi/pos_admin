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
