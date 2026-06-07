<?php

declare(strict_types=1);

namespace App\Logging;

use App\Support\TenantContext;
use Illuminate\Http\Request;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor that decorates every log record with the
 * cross-cutting context fields the blueprint (§9.4) requires:
 *
 *   trace_id    : same X-Request-Id we set on the response so a
 *                 line in storage/logs/laravel.log links 1:1 with
 *                 the in-flight HTTP request (and the matching
 *                 Sentry event, which uses the same id).
 *   user_id     : authenticated platform admin's pos_users.id,
 *                 null when the request is unauthenticated.
 *   company_id  : tenant in scope when SetTenantContext resolved
 *                 one; null on platform-admin endpoints.
 *   request_id  : alias of trace_id (kept separate so log shippers
 *                 can map both keys if they're already wired for
 *                 either spelling).
 *   route       : Laravel route name (admin.api.v1.devices.index)
 *                 — easier to grep than a raw path with ids in it.
 *   method      : HTTP verb.
 *   path        : URI path component without query string.
 *   ip          : client IP from the request resolver.
 *
 * Console + queue contexts have no Request, so we fall back to a
 * conservative subset (the cli command + the process pid). That
 * way scheduled jobs still emit structured logs that match the
 * web ones in shape.
 *
 * Wired in {@see config/logging.php} as a processor on the `json`
 * channel. The processor is registered via the service container
 * so its TenantContext singleton is the same instance the middleware
 * stamps — otherwise we'd read a fresh blank context.
 */
class AppContextProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $request = $this->resolveRequest();

        // Two cases: HTTP request in flight, or CLI / queue context.
        $contextFields = $request !== null
            ? $this->httpContext($request)
            : $this->cliContext();

        // Merge into the record's `extra` array (Monolog's standard
        // dumping ground for processor output — the JSON formatter
        // promotes these fields up to top-level keys).
        return $record->with(extra: array_merge($record->extra, $contextFields));
    }

    /**
     * @return array<string, mixed>
     */
    private function httpContext(Request $request): array
    {
        $traceId = (string) ($request->attributes->get('request_id')
            ?: $request->headers->get('X-Request-Id')
            ?: '');

        // user() returns null on unauth requests and (rarely) on
        // CLI bootstrap paths where the auth guard hasn't resolved.
        $user = $request->user();

        return [
            'trace_id' => $traceId ?: null,
            'request_id' => $traceId ?: null,
            'user_id' => $user?->getAuthIdentifier(),
            'company_id' => $this->tenant->id(),
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'route' => optional($request->route())->getName(),
            'ip' => $request->ip(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cliContext(): array
    {
        // The artisan command name (e.g. `schedule:run`) is stashed
        // in $_SERVER['argv'] by Symfony Console — pull the first
        // positional arg as a best-effort label.
        $argv = $_SERVER['argv'] ?? [];
        $command = is_array($argv) && isset($argv[1]) ? (string) $argv[1] : null;

        return [
            'trace_id' => null,
            'request_id' => null,
            'user_id' => null,
            'company_id' => $this->tenant->id(),
            'command' => $command,
            'pid' => getmypid() ?: null,
        ];
    }

    /**
     * Resolve the current request from the container WITHOUT throwing
     * if we're outside an HTTP context (CLI/queue). Returning null
     * lets {@see __invoke} pick the cliContext() branch instead.
     */
    private function resolveRequest(): ?Request
    {
        try {
            $request = app('request');
        } catch (\Throwable) {
            return null;
        }

        return $request instanceof Request ? $request : null;
    }
}
