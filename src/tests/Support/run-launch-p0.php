<?php

declare(strict_types=1);

// One repeatable job shared by API and merchant CI. No application bootstrap
// here: each child boots its own container and its own Composer autoloader.
$host = getenv('DB_HOST') ?: '';
if (getenv('LAUNCH_P0_DISPOSABLE') !== '1' || ! in_array($host, ['qrfix2-pg', 'localhost', '127.0.0.1'], true)
    || getenv('DB_USERNAME') !== 'p0_test' || getenv('APP_ENV') !== 'testing') {
    throw new RuntimeException('Explicit disposable local PostgreSQL configuration required.');
}
$roots = [
    'api' => realpath(getenv('P0_API_ROOT') ?: __DIR__.'/../../../../pos_api/src'),
    'merchant' => realpath(getenv('P0_MERCHANT_ROOT') ?: __DIR__.'/../../../../pos_merchant/src'),
    'admin' => realpath(getenv('P0_ADMIN_ROOT') ?: __DIR__.'/../..'),
];
foreach ($roots as $root) {
    if (! $root || ! is_file($root.'/vendor/autoload.php')) {
        throw new RuntimeException('Missing checkout dependencies');
    }
}
$databasePrefix = 'launch_p0_fix1_'.bin2hex(random_bytes(6));
$databases = array_combine(['core', 'staff', 'merge', 'plate'],
    array_map(static fn (string $suffix): string => $databasePrefix.'_'.$suffix, ['core', 'staff', 'merge', 'plate']));
$state = sys_get_temp_dir().'/launch-p0-races-'.bin2hex(random_bytes(6));
mkdir($state, 0700);
$env = array_merge(getenv(), [
    'P0_DATABASE_PREFIX' => $databasePrefix,
    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '', 'CHARITY_API_URL' => '',
    'BROADCAST_CONNECTION' => 'null', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'POS_RACE_STATE_DIR' => $state,
    'APP_CONFIG_CACHE' => $state.'/no-cached-config.php', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('A', 32)),
]);
$connect = static fn (string $db): PDO => new PDO(
    'pgsql:host='.$host.';port='.(getenv('DB_PORT') ?: '5432').';dbname='.$db,
    'p0_test', getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$start = static function (string $app, array $args, string $db) use ($roots, $env, $state): array {
    $log = tempnam($state, 'process-');
    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=768M', ...$args],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
        $pipes, $roots[$app], array_merge($env, ['DB_DATABASE' => $db]));
    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start '.$app);
    }

    return [$process, $log, $app, $args, microtime(true)];
};
$finish = static function (array $child): string {
    [$process, $log, $app, $args, $started] = $child;
    do {
        $status = proc_get_status($process);
        if (! $status['running']) {
            break;
        }
        if (microtime(true) - $started > 180) {
            proc_terminate($process);
            throw new RuntimeException('Child timed out: '.$app.' '.implode(' ', $args));
        }
        usleep(20000);
    } while (true);
    proc_close($process);
    $out = file_get_contents($log);
    echo $out;
    if ($status['exitcode'] !== 0) {
        throw new RuntimeException('Failed: '.$app.' '.implode(' ', $args));
    }

    return $out;
};
$run = static fn (string $app, array $args, string $db): string => $finish($start($app, $args, $db));
$wait = static function (PDO $pdo, string $predicate): void {
    $deadline = microtime(true) + 20;
    do {
        $pdo->query('SELECT pg_stat_clear_snapshot()');
        if ((int) $pdo->query("SELECT COUNT(*) FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock' AND (".$predicate.')')->fetchColumn() > 0) {
            return;
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Real PostgreSQL lock barrier not reached: '.$predicate);
};
$server = $connect('postgres');
foreach ($databases as $db) {
    // A cryptographically unique name belongs to this invocation. CREATE must
    // fail on a collision; an existing database is never reset or reused.
    $server->exec('CREATE DATABASE "'.$db.'"');
    $run('admin', ['artisan', 'migrate', '--force', '--no-interaction'], $db);
    echo 'REAL_ADMIN_SCHEMA '.$db." PASS\n";
}
$run('api', ['vendor/bin/phpunit', '-c', 'phpunit.postgres.xml', '--colors=never'], $databases['core']);
$run('merchant', ['vendor/bin/phpunit', '-c', 'phpunit.postgres.xml', '--colors=never'], $databases['core']);
$run('api', ['tests/Postgres/CanonicalCustomerRace.php'], $databases['core']);
$run('merchant', ['tests/Postgres/MergeEarnRace.php'], $databases['core']);
$run('api', ['vendor/bin/phpunit', '-c', 'phpunit.postgres.xml', 'tests/Postgres/MergedReplayTest.php', '--colors=never'], $databases['core']);
$run('api', ['vendor/bin/phpunit', '-c', 'phpunit.postgres.xml', 'tests/Postgres/StaffRoundSettlementRaceTest.php', '--colors=never'], $databases['staff']);

foreach (['create', 'hold', 'transfer', 'attach-http', 'attach-sync'] as $kind) {
    $db = $databases['merge'];
    $label = 'p0-'.$kind;
    $run('api', ['tests/Postgres/Fix5MergeWrite.php', 'seed', $label, $kind], $db);
    $fixture = json_decode(file_get_contents($state.'/'.$label.'.json'), true, flags: JSON_THROW_ON_ERROR);
    $actorOutput = $run('merchant', ['tests/Postgres/Fix5MergeActor.php', 'actor', (string) $fixture['company']], $db);
    preg_match('/ACTOR (.+)/', $actorOutput, $match);
    $actor = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR)['actor'];
    $gate = $connect($db);
    $observer = $connect($db);
    $gate->beginTransaction();
    $gate->query('SELECT id FROM pos_loyalty_accounts WHERE id = '.(int) $fixture['account'].' FOR UPDATE');
    $children = [];
    try {
        $children[] = $start('merchant', ['artisan', 'customers:merge-duplicates', '--company='.$fixture['company'], '--actor='.$actor, '--apply', '--only='.$fixture['survivor'].':'.$fixture['source']], $db);
        $wait($observer, "query ILIKE '%pos_loyalty_accounts%for update%'");
        $children[] = $start('api', ['tests/Postgres/Fix5MergeWrite.php', 'write', $label], $db);
        $wait($observer, "query ILIKE '%pos_customers%for share%' OR query ILIKE 'insert into %pos_orders%' OR query ILIKE 'update %pos_orders%'");
    } finally {
        $gate->commit();
        foreach ($children as $child) {
            $finish($child);
        }
    }
    $run('api', ['tests/Postgres/Fix5MergeWrite.php', 'pay', $label], $db);
    echo 'PASS FIX5 '.$kind." real merge/write lock, replayed tender\n";
}
for ($repeat = 0; $repeat < 3; $repeat++) {
    $db = $databases['plate'];
    $label = 'p0-plate-'.$repeat;
    $run('api', ['tests/Postgres/Fix6PlateAttachRace.php', 'seed', $label], $db);
    $fixture = json_decode(file_get_contents($state.'/'.$label.'.json'), true, flags: JSON_THROW_ON_ERROR);
    $gate = $connect($db);
    $observer = $connect($db);
    $deadlocks = static fn (): int => (int) $observer->query('SELECT deadlocks FROM pg_stat_database WHERE datname = current_database()')->fetchColumn();
    $before = $deadlocks();
    $gate->query('SELECT pg_advisory_lock('.(int) $fixture['gate'].')');
    $children = [];
    try {
        $children[] = $start('api', ['tests/Postgres/Fix6PlateAttachRace.php', 'round', $label], $db);
        $wait($observer, "query ILIKE '%SELECT pg_advisory_lock%'");
        $children[] = $start('api', ['tests/Postgres/Fix6PlateAttachRace.php', 'attach', $label], $db);
        $wait($observer, "query ILIKE '%for update%'");
    } finally {
        $gate->query('SELECT pg_advisory_unlock('.(int) $fixture['gate'].')');
        foreach ($children as $child) {
            $finish($child);
        }
    }
    $run('api', ['tests/Postgres/Fix6PlateAttachRace.php', 'check', $label], $db);
    if ($deadlocks() !== $before) {
        throw new RuntimeException('Unexpected plate/attach deadlock');
    }
    echo 'PASS FIX6 '.$repeat." real plate/attach lock, no deadlocks\n";
}
echo "LAUNCH_P0_POSTGRES_JOB PASS: real admin schema; API and merchant tenant isolation; all existing PostgreSQL race harnesses.\n";
