<?php

declare(strict_types=1);

use App\Jobs\Admin\ScanExpiringCompanyDocumentsJob;
use Illuminate\Console\Scheduling\Schedule;

it('registers the roundup retry hourly with distributed overlap guards', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => $event->description === 'retry-roundup-forwarding');

    expect($event)->not->toBeNull();

    if ($event === null) {
        return;
    }

    expect((string) $event->command)->toContain('donations:retry-roundup-forwarding')
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(60)
        ->and($event->onOneServer)->toBeTrue();
});

it('dispatches the document scan daily with distributed overlap guards', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => $event->description === 'scan-expiring-company-documents');

    expect($event)->not->toBeNull();

    if ($event === null) {
        return;
    }

    expect($event->expression)->toBe('0 2 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(60)
        ->and($event->onOneServer)->toBeTrue();
});

it('keeps the Redis retry window beyond the longest queued job timeout', function (): void {
    $retryAfter = config('queue.connections.redis.retry_after');
    $job = new ScanExpiringCompanyDocumentsJob;

    expect($retryAfter)->toBeInt()
        ->and($retryAfter)->toBe(660)
        ->and($retryAfter)->toBeGreaterThan($job->timeout);
});

it('keeps deployment templates on canonical shared infrastructure tables', function (): void {
    $requiredSettings = [
        'CACHE_STORE=redis',
        'QUEUE_CONNECTION=redis',
        'REDIS_QUEUE_RETRY_AFTER=660',
        'REDIS_HOST=pos_admin_redis',
        'AUTH_PASSWORD_RESET_TOKEN_TABLE=password_reset_tokens',
        'SESSION_TABLE=sessions',
        'DB_CACHE_TABLE=cache',
        'DB_CACHE_LOCK_TABLE=cache_locks',
        'DB_QUEUE_TABLE=jobs',
        'QUEUE_BATCHES_TABLE=job_batches',
        'QUEUE_FAILED_TABLE=failed_jobs',
    ];

    foreach (['.env.example', '.env.production.example'] as $template) {
        $contents = file_get_contents(base_path($template));

        expect($contents)->not->toBeFalse();

        if ($contents === false) {
            continue;
        }

        foreach ($requiredSettings as $setting) {
            expect($contents)->toContain($setting);
        }
    }
});

it('keeps the production scheduler and queue worker active with the shared runtime contract', function (): void {
    $compose = file_get_contents(base_path('../docker-compose.prod.yml'));

    expect($compose)->not->toBeFalse();

    if ($compose === false) {
        return;
    }

    $serviceBlock = static function (string $service) use ($compose): ?string {
        $marker = "\n  {$service}:\n";
        $yaml = "\n{$compose}";
        $start = strpos($yaml, $marker);

        if ($start === false) {
            return null;
        }

        $block = substr($yaml, $start + strlen($marker));

        if (preg_match('/\n  [a-zA-Z0-9_-]+:\n/', $block, $match, PREG_OFFSET_CAPTURE) === 1) {
            $block = substr($block, 0, $match[0][1]);
        }

        return $block;
    };

    $scheduler = $serviceBlock('pos_admin_scheduler');
    $worker = $serviceBlock('pos_admin_queue_worker');

    expect($scheduler)->not->toBeNull()
        ->and($worker)->not->toBeNull();

    if ($scheduler === null || $worker === null) {
        return;
    }

    foreach ([$scheduler, $worker] as $service) {
        expect($service)->toContain('<<: *pos-admin-runtime-environment')
            ->and($service)->toContain('- ./src:/var/www/html')
            ->and($service)->toContain('- storage-data:/var/www/html/storage')
            ->and($service)->toContain('- cache-data:/var/www/html/bootstrap/cache')
            ->and($service)->toContain('- pos_admin_redis')
            ->and($service)->not->toContain('profiles:');
    }

    expect($scheduler)->toContain('command: ["php", "artisan", "schedule:work"]')
        ->and($scheduler)->toContain('restart: unless-stopped')
        ->and($worker)->toContain('restart: unless-stopped')
        ->and($worker)->toContain('stop_grace_period: 11m')
        ->and($worker)->toContain('- queue:work')
        ->and($worker)->toContain('- redis')
        ->and($worker)->toContain('- --queue=default')
        ->and($worker)->toContain('- --tries=3')
        ->and($worker)->toContain('- --timeout=600')
        ->and($worker)->toContain('- --max-time=3600');

    $servicesMarker = strpos($compose, "\nservices:\n");

    expect($servicesMarker)->not->toBeFalse();

    if ($servicesMarker === false) {
        return;
    }

    $anchor = substr($compose, 0, $servicesMarker);
    $canonicalSettings = [
        'APP_ENV: production',
        'APP_DEBUG: "false"',
        'LOG_CHANNEL: stderr',
        'CACHE_STORE: redis',
        'QUEUE_CONNECTION: redis',
        'REDIS_QUEUE_RETRY_AFTER: "660"',
        'REDIS_HOST: pos_admin_redis',
        'AUTH_PASSWORD_RESET_TOKEN_TABLE: password_reset_tokens',
        'SESSION_TABLE: sessions',
        'DB_CACHE_TABLE: cache',
        'DB_CACHE_LOCK_TABLE: cache_locks',
        'DB_QUEUE_TABLE: jobs',
        'QUEUE_BATCHES_TABLE: job_batches',
        'QUEUE_FAILED_TABLE: failed_jobs',
    ];

    foreach ($canonicalSettings as $setting) {
        expect($anchor)->toContain($setting);
    }
});
