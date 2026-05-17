<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies a baseline of conservative response headers to every HTTP response.
 *
 * CSP is intentionally permissive enough for the Vite dev server during
 * development and tight for production. Adjust via {@see self::contentSecurityPolicy()}
 * if a new asset host is introduced.
 */
class SecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-site',
            'Content-Security-Policy' => $this->contentSecurityPolicy(),
        ];

        if ($request->secure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains; preload';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        $viteOrigins = array_filter(array_map('trim', explode(',', (string) env('VITE_DEV_SERVER_CORS_ORIGINS', ''))));
        $viteSources = implode(' ', $viteOrigins);
        $isProduction = app()->isProduction();

        $scriptSrc = $isProduction
            ? "'self'"
            : "'self' 'unsafe-inline' 'unsafe-eval' ".$viteSources;

        $styleSrc = $isProduction
            ? "'self' 'unsafe-inline'"
            : "'self' 'unsafe-inline' ".$viteSources;

        $connectSrc = $isProduction
            ? "'self'"
            : "'self' ws: wss: ".$viteSources;

        return implode('; ', array_filter([
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "script-src {$scriptSrc}",
            "style-src {$styleSrc}",
            "connect-src {$connectSrc}",
        ]));
    }
}
