<?php

declare(strict_types=1);

/*
 * LAUNCH-P1 operations items:
 *  - P1-16 the backup also archives the private merchant documents;
 *  - P1-7  the production PHP image accepts 10 MB uploads;
 *  - P1-3  mail works by configuration only (documented SMTP settings,
 *          MAIL_ENCRYPTION honoured, no committed password).
 */

use Dotenv\Dotenv;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Run the real backup script with a stub pg_dump on PATH.
 *
 * @param  array<string, string>  $env
 */
function p1RunBackup(string $work, array $env): ProcessResult
{
    $bin = "{$work}/bin";
    File::ensureDirectoryExists($bin);
    file_put_contents("{$bin}/pg_dump", "#!/usr/bin/env bash\necho FAKE-PG-DUMP\n");
    chmod("{$bin}/pg_dump", 0755);

    return Process::path($work)->env(array_merge([
        'PATH' => $bin.':'.getenv('PATH'),
        'PGHOST' => 'db.invalid',
        'PGUSER' => 'backup',
        'PGDATABASE' => 'charity_db',
        'BACKUP_LOCAL_DIR' => "{$work}/out",
        'BACKUP_KEY_FILE' => "{$work}/key",
    ], $env))->run(['bash', base_path('../ops/backup/charity-db-backup.sh')]);
}

it('backs up the private merchant documents next to the database dump', function (): void {
    $work = storage_path('framework/testing/p1-backup-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists("{$work}/docs/companies/abc");
    file_put_contents("{$work}/docs/companies/abc/cr.pdf", '%PDF-1.4 cr certificate');
    file_put_contents("{$work}/key", 'test-backup-key');

    try {
        $result = p1RunBackup($work, ['DOCUMENTS_DIR' => "{$work}/docs"]);
        expect($result->exitCode())->toBe(0, $result->errorOutput());

        $archives = glob("{$work}/out/documents_*.tar.gz.enc") ?: [];
        $dumps = glob("{$work}/out/charity_db_*.dump.gz.enc") ?: [];
        expect($archives)->toHaveCount(1)->and($dumps)->toHaveCount(1);

        // Same run, same timestamp.
        $stamp = fn (string $p): string => (string) preg_replace('/^.*_(\d{8}T\d{6}Z)\..*$/', '$1', basename($p));
        expect($stamp($archives[0]))->toBe($stamp($dumps[0]));

        // The documented restore command recovers the file intact.
        $restore = Process::run([
            'bash', '-c',
            'openssl enc -d -aes-256-cbc -pbkdf2 -pass file:"$1" -in "$2" | tar -xzO ./companies/abc/cr.pdf',
            'restore', "{$work}/key", $archives[0],
        ]);
        expect($restore->exitCode())->toBe(0, $restore->errorOutput())
            ->and($restore->output())->toBe('%PDF-1.4 cr certificate');
    } finally {
        File::deleteDirectory($work);
    }
});

it('backs up the database and succeeds when no documents folder exists yet', function (): void {
    $work = storage_path('framework/testing/p1-backup-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($work);
    file_put_contents("{$work}/key", 'test-backup-key');

    try {
        // No document was ever uploaded: the documents disk has not
        // created its directory yet. The database backup must not suffer.
        $result = p1RunBackup($work, ['DOCUMENTS_DIR' => "{$work}/not-created-yet"]);

        expect($result->exitCode())->toBe(0, $result->errorOutput())
            ->and($result->errorOutput())->toContain('WARN: merchant documents directory not found')
            ->and($result->output())->toContain('database backup OK');
        expect(glob("{$work}/out/charity_db_*.dump.gz.enc") ?: [])->toHaveCount(1)
            ->and(glob("{$work}/out/documents_*.tar.gz.enc") ?: [])->toHaveCount(0);
    } finally {
        File::deleteDirectory($work);
    }
});

it('keeps the database dump and fails loudly when an existing documents folder cannot be archived', function (): void {
    $work = storage_path('framework/testing/p1-backup-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists("{$work}/docs/companies/abc");
    File::ensureDirectoryExists("{$work}/bin");
    file_put_contents("{$work}/docs/companies/abc/id.jpg", 'owner id');
    file_put_contents("{$work}/key", 'test-backup-key');
    // A broken tar (e.g. an unreadable file) — archiving fails.
    file_put_contents("{$work}/bin/tar", "#!/usr/bin/env bash\necho 'tar: simulated read error' >&2\nexit 2\n");
    chmod("{$work}/bin/tar", 0755);

    try {
        $result = p1RunBackup($work, ['DOCUMENTS_DIR' => "{$work}/docs"]);

        expect($result->exitCode())->toBe(1)
            ->and($result->errorOutput())->toContain('FATAL: archiving the merchant documents');
        // The database dump of this run was finished first and is kept;
        // no half-written documents archive is left behind.
        expect(glob("{$work}/out/charity_db_*.dump.gz.enc") ?: [])->toHaveCount(1)
            ->and(glob("{$work}/out/documents_*.tar.gz.enc") ?: [])->toHaveCount(0);
    } finally {
        File::deleteDirectory($work);
    }
});

it('lets the production PHP image accept a 10 MB document', function (): void {
    $ini = base_path('../docker/prod/php/uploads.ini');
    expect(is_file($ini))->toBeTrue();

    $values = parse_ini_file($ini);
    $bytes = static function (string $v): int {
        $unit = strtoupper(substr($v, -1));
        $n = (int) $v;

        return match ($unit) {
            'G' => $n * 1024 ** 3,
            'M' => $n * 1024 ** 2,
            'K' => $n * 1024,
            default => $n,
        };
    };

    expect($bytes((string) $values['upload_max_filesize']))->toBeGreaterThanOrEqual(10 * 1024 ** 2)
        ->and($bytes((string) $values['post_max_size']))->toBeGreaterThan($bytes((string) $values['upload_max_filesize']));

    expect((string) file_get_contents(base_path('../docker/prod/php/php.dockerfile')))
        ->toContain('COPY docker/prod/php/uploads.ini /usr/local/etc/php/conf.d/');
});

it('documents SMTP settings in the production env template without a password', function (): void {
    $env = Dotenv::parse((string) file_get_contents(base_path('.env.production.example')));

    expect($env['MAIL_MAILER'] ?? null)->toBe('smtp')
        ->and($env)->toHaveKeys(['MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_ENCRYPTION'])
        ->and($env['MAIL_PASSWORD'])->toBe('')
        ->and($env['MAIL_FROM_ADDRESS'] ?? null)->toBe('noreply@mithqal.net')
        ->and($env['MAIL_FROM_NAME'] ?? null)->toBe('MITHQAL')
        ->and($env['MERCHANT_PORTAL_URL'] ?? '')->toStartWith('https://')
        ->and($env['DOCUMENTS_DISK_DRIVER'] ?? null)->toBe('local');
});

it('honours MAIL_ENCRYPTION=ssl as implicit TLS and bounds the SMTP wait', function (): void {
    $saved = [$_ENV['MAIL_ENCRYPTION'] ?? null, $_SERVER['MAIL_ENCRYPTION'] ?? null];
    $_ENV['MAIL_ENCRYPTION'] = $_SERVER['MAIL_ENCRYPTION'] = 'ssl';

    try {
        $config = require config_path('mail.php');
    } finally {
        [$env, $server] = $saved;
        if ($env === null) {
            unset($_ENV['MAIL_ENCRYPTION']);
        } else {
            $_ENV['MAIL_ENCRYPTION'] = $env;
        }
        if ($server === null) {
            unset($_SERVER['MAIL_ENCRYPTION']);
        } else {
            $_SERVER['MAIL_ENCRYPTION'] = $server;
        }
    }

    expect($config['mailers']['smtp']['scheme'])->toBe('smtps')
        ->and($config['mailers']['smtp']['timeout'])->toBeInt()->toBeGreaterThan(0);
});
