<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
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
        // Generate a per-response CSP nonce BEFORE the view renders, so the
        // @vite bundle tags + the app.blade.php bootstrap <script> (which
        // injects the initial auth state) can carry it. The production CSP then
        // allows inline scripts ONLY via this nonce — without it the bootstrap
        // script is blocked and the SPA never learns who is signed in (which
        // bounces the user to /login in an endless redirect loop).
        Vite::useCspNonce();

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

        // Production: scripts from 'self' + the per-request nonce (carried by
        // the @vite bundle tags and the app.blade.php bootstrap <script>). Dev
        // keeps 'unsafe-inline'/'unsafe-eval' for the Vite HMR client.
        $nonce = Vite::cspNonce();
        $nonceSource = ($nonce !== null && $nonce !== '') ? " 'nonce-{$nonce}'" : '';

        $scriptSrc = $isProduction
            ? "'self'".$nonceSource
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

        // Google Maps JS API (device route map + branch location picker).
        // The loader injects a <script> from maps.googleapis.com, pulls map
        // tiles + marker images from the gstatic / googleapis CDNs, makes XHR
        // calls back to maps.googleapis.com, and uses the Roboto webfont.
        // Allowed in both dev + production (the maps are needed in both).
        $scriptSrc = trim($scriptSrc.' https://maps.googleapis.com https://maps.gstatic.com');
        $connectSrc = trim($connectSrc.' https://maps.googleapis.com https://*.googleapis.com');

        // Cloudflare auto-injects its Web Analytics beacon when the zone is
        // proxied (orange-cloud). Allow it so it doesn't spam CSP violations;
        // harmless to keep even if CF analytics is later disabled.
        $scriptSrc = trim($scriptSrc.' https://static.cloudflareinsights.com');
        $connectSrc = trim($connectSrc.' https://cloudflareinsights.com');
        $imgSrc = trim($imgSrc.' https://maps.googleapis.com https://maps.gstatic.com https://*.googleapis.com https://*.gstatic.com https://*.google.com https://*.ggpht.com');
        $fontSrc = trim($fontSrc.' https://fonts.gstatic.com');
        $styleSrc = trim($styleSrc.' https://fonts.googleapis.com');

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
            // Google Maps' vector renderer runs in a blob: web worker.
            "worker-src 'self' blob:",
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
