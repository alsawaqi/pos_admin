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
        $isProduction = app()->isProduction();
        $viteHttpOrigins = $this->viteHttpOrigins();
        $viteWsOrigins = $this->viteWebsocketOrigins($viteHttpOrigins);

        $scriptSrc = $isProduction
            ? "'self'"
            : trim("'self' 'unsafe-inline' 'unsafe-eval' ".implode(' ', $viteHttpOrigins));

        $styleSrc = $isProduction
            ? "'self' 'unsafe-inline'"
            : trim("'self' 'unsafe-inline' ".implode(' ', $viteHttpOrigins));

        $connectSrc = $isProduction
            ? "'self'"
            : trim("'self' ".implode(' ', [...$viteHttpOrigins, ...$viteWsOrigins]));

        $imgSrc = $isProduction
            ? "'self' data: blob:"
            : trim("'self' data: blob: ".implode(' ', $viteHttpOrigins));

        $fontSrc = $isProduction
            ? "'self' data:"
            : trim("'self' data: ".implode(' ', $viteHttpOrigins));

        return implode('; ', array_filter([
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "img-src {$imgSrc}",
            "font-src {$fontSrc}",
            "script-src {$scriptSrc}",
            "script-src-elem {$scriptSrc}",
            "style-src {$styleSrc}",
            "style-src-elem {$styleSrc}",
            "connect-src {$connectSrc}",
        ]));
    }

    /**
     * Origins the browser may load Vite assets from.
     *
     * Combines VITE_DEV_SERVER_URL (where the dev server is actually served —
     * usually http://localhost:5174) with VITE_DEV_SERVER_CORS_ORIGINS
     * (the Laravel app origins, kept as a fallback so 127.0.0.1 ↔ localhost
     * mixups are tolerated).
     *
     * @return list<string>
     */
    private function viteHttpOrigins(): array
    {
        $candidates = [(string) env('VITE_DEV_SERVER_URL', '')];

        foreach (explode(',', (string) env('VITE_DEV_SERVER_CORS_ORIGINS', '')) as $entry) {
            $candidates[] = $entry;
        }

        $origins = [];

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '') {
                continue;
            }

            $origins[$candidate] = true;
        }

        return array_keys($origins);
    }

    /**
     * Websocket variants of the Vite origins for HMR. Mirrors each http(s)
     * origin as ws(s) so the dev server's hot-reload socket survives CSP.
     *
     * @param  list<string>  $httpOrigins
     * @return list<string>
     */
    private function viteWebsocketOrigins(array $httpOrigins): array
    {
        $wsOrigins = [];

        foreach ($httpOrigins as $origin) {
            if (str_starts_with($origin, 'https://')) {
                $wsOrigins['wss://'.substr($origin, 8)] = true;
            } elseif (str_starts_with($origin, 'http://')) {
                $wsOrigins['ws://'.substr($origin, 7)] = true;
            }
        }

        return array_keys($wsOrigins);
    }
}
