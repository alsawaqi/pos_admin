<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use function Sentry\configureScope;
use function Sentry\SentrySdk;

/**
 * Attaches per-request context to Sentry's active scope so the
 * dashboard at sentry.io shows WHO and IN WHICH TENANT each error
 * happened — far more useful than a raw stack trace.
 *
 * What gets attached:
 *   - user.id / user.email — for the "see all errors from this
 *     admin" filter.
 *   - tag company_id — for the "errors in tenant X" filter.
 *   - tag request_id — same trace id we put on the JSON log line
 *     so a Sentry alert links back to the exact log entries.
 *
 * Intentionally a no-op (apart from the trivial scope mutation)
 * when SENTRY_LARAVEL_DSN is empty — the SDK's hub becomes a
 * NullHub in that case and configureScope still works without
 * actually shipping anything anywhere. Safe to leave in the
 * middleware stack for local dev.
 *
 * Wired in {@see \App\Http\Kernel} (bootstrap/app.php) right after
 * SetTenantContext so the tenant id is already resolved by the
 * time we reach this middleware.
 */
class AttachSentryContext
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Generate a request id up-front so it can flow through
        // both Sentry tags AND the JSON log formatter (see
        // App\Logging\AppContextProcessor). The header form is
        // standard X-Request-Id; we honour an incoming one (from
        // an upstream load balancer that already assigned one) or
        // generate a fresh UUID-shaped id otherwise.
        $requestId = (string) ($request->headers->get('X-Request-Id') ?: bin2hex(random_bytes(8)));
        $request->headers->set('X-Request-Id', $requestId);
        $request->attributes->set('request_id', $requestId);

        // configureScope is a no-op when no Sentry DSN is set, so
        // we can always call it. Keeps the middleware single-purpose.
        configureScope(function (\Sentry\State\Scope $scope) use ($request, $requestId): void {
            $user = $request->user();
            if ($user !== null) {
                $scope->setUser([
                    'id' => $user->getAuthIdentifier(),
                    // Email is the most useful identifier in a
                    // platform-admin context; per blueprint §9.13
                    // it isn't considered PII for our own staff.
                    'email' => method_exists($user, 'getEmailForVerification')
                        ? $user->getEmailForVerification()
                        : ($user->email ?? null),
                ]);
            }

            if ($this->tenant->has()) {
                $scope->setTag('company_id', (string) $this->tenant->id());
            }

            $scope->setTag('request_id', $requestId);
        });

        $response = $next($request);

        // Echo the request id back to the client too so support
        // can copy/paste it from a UI surface into Sentry's
        // "find this error" search.
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
