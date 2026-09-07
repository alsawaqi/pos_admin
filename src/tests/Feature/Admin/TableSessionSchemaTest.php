<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Device;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

// No RefreshDatabase: SQLite must be able to disable FK enforcement during a
// table rebuild. An outer test transaction would prevent that PRAGMA taking effect.
function tableSessionSchemaDatabase(Closure $assertions): void
{
    $name = 'qr003_t2_schema';
    $previousDefault = DB::getDefaultConnection();
    $previousConfig = config("database.connections.{$name}");
    $previousResolver = Model::getConnectionResolver();
    config(["database.connections.{$name}" => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);

    try {
        DB::purge($name);
        DB::setDefaultConnection($name);
        Schema::clearResolvedInstance('db.schema');
        Model::setConnectionResolver(app('db'));
        Assert::assertSame('sqlite', DB::connection()->getDriverName());
        Assert::assertSame(':memory:', DB::connection()->getDatabaseName());
        Assert::assertSame(0, DB::transactionLevel());
        Assert::assertSame(0, Artisan::call('migrate', [
            '--database' => $name,
            '--force' => true,
        ]), Artisan::output());

        $assertions();
    } finally {
        DB::purge($name);
        DB::setDefaultConnection($previousDefault);
        Schema::clearResolvedInstance('db.schema');
        Model::setConnectionResolver($previousResolver);
        config(["database.connections.{$name}" => $previousConfig]);
    }
}

/** @return array<string, list<string>> */
function tableSessionAddedColumns(): array
{
    return [
        'pos_qr_sessions' => [
            'table_session_id', 'origin', 'scan_fingerprint_hash', 'scan_ip_hash', 'scan_geofence_verdict',
            'released_at', 'handover_from_id',
        ],
        'pos_qr_order_rounds' => [
            'table_session_id', 'origin_table_session_id', 'kitchen_printed_at', 'needs_review',
        ],
        'pos_orders' => ['table_session_id'],
        'pos_order_items' => ['cancel_disposition', 'cancelled_at'],
    ];
}

function assertTableSessionColumnsAndForeignKeys(): void
{
    $tables = [
        'pos_table_sessions' => [
            'columns' => [
                'id', 'uuid', 'company_id', 'branch_id', 'table_id', 'status', 'origin',
                'opened_by_device_id', 'closed_by_device_id', 'order_id', 'merged_into_id',
                'temp_reference', 'client_request_id', 'opened_at', 'expires_at', 'billing_at',
                'closed_at', 'close_reason', 'created_at', 'updated_at',
            ],
            'required' => ['id', 'uuid', 'company_id', 'branch_id', 'table_id', 'status', 'origin', 'opened_at', 'expires_at'],
            'foreign' => [
                'company_id' => ['pos_companies', 'CASCADE'],
                'branch_id' => ['pos_branches', 'CASCADE'],
                'table_id' => ['pos_tables', 'CASCADE'],
                'opened_by_device_id' => ['pos_devices', 'SET NULL'],
                'closed_by_device_id' => ['pos_devices', 'SET NULL'],
                'order_id' => ['pos_orders', 'SET NULL'],
                'merged_into_id' => ['pos_table_sessions', 'SET NULL'],
            ],
        ],
        'pos_table_session_events' => [
            'columns' => [
                'id', 'company_id', 'branch_id', 'table_session_id', 'table_id',
                'event_type', 'device_id', 'payload', 'created_at',
            ],
            'required' => ['id', 'company_id', 'branch_id', 'table_session_id', 'table_id', 'event_type', 'created_at'],
            'foreign' => [
                'company_id' => ['pos_companies', 'CASCADE'],
                'branch_id' => ['pos_branches', 'CASCADE'],
                'table_session_id' => ['pos_table_sessions', 'CASCADE'],
                'table_id' => ['pos_tables', 'CASCADE'],
                'device_id' => ['pos_devices', 'SET NULL'],
            ],
        ],
        'pos_qr_session_scans' => [
            'columns' => [
                'id', 'company_id', 'branch_id', 'table_id', 'qr_session_id', 'table_session_id',
                'role', 'device_fingerprint_hash', 'ip_hash', 'latitude', 'longitude',
                'geofence_verdict', 'scanned_at', 'created_at',
                'outcome', 'accuracy_m', 'distance_m',
            ],
            'required' => ['id', 'company_id', 'branch_id', 'role', 'scanned_at', 'created_at'],
            'foreign' => [
                'company_id' => ['pos_companies', 'CASCADE'],
                'branch_id' => ['pos_branches', 'CASCADE'],
                'table_id' => ['pos_tables', 'SET NULL'],
                'qr_session_id' => ['pos_qr_sessions', 'SET NULL'],
                'table_session_id' => ['pos_table_sessions', 'SET NULL'],
            ],
        ],
        'pos_kitchen_tickets' => [
            'columns' => [
                'id', 'company_id', 'branch_id', 'ticket_key', 'round_id', 'order_id',
                'claimed_by_device_id', 'claimed_at', 'printed_at', 'print_result', 'created_at', 'updated_at',
            ],
            'required' => ['id', 'company_id', 'branch_id', 'ticket_key', 'claimed_at'],
            'foreign' => [
                'company_id' => ['pos_companies', 'CASCADE'],
                'branch_id' => ['pos_branches', 'CASCADE'],
                'round_id' => ['pos_qr_order_rounds', 'SET NULL'],
                'order_id' => ['pos_orders', 'SET NULL'],
                'claimed_by_device_id' => ['pos_devices', 'SET NULL'],
            ],
        ],
    ];

    foreach ($tables as $table => $definition) {
        Assert::assertSame($definition['columns'], Schema::getColumnListing($table), $table.' exact columns');
        $columns = collect(DB::select("PRAGMA table_info('{$table}')"))->keyBy('name');
        foreach ($definition['columns'] as $column) {
            Assert::assertSame(
                (int) in_array($column, $definition['required'], true),
                (int) $columns->get($column)->notnull,
                "{$table}.{$column} nullability",
            );
            $default = $columns->get($column)->dflt_value;
            if ($table === 'pos_table_sessions' && $column === 'status') {
                Assert::assertSame('open', trim((string) $default, "'"));
            } else {
                Assert::assertNull($default, "{$table}.{$column} default");
            }
        }

        $foreignKeys = collect(DB::select("PRAGMA foreign_key_list('{$table}')"))->keyBy('from');
        Assert::assertCount(count($definition['foreign']), $foreignKeys, $table.' exact foreign keys');
        foreach ($definition['foreign'] as $column => [$target, $onDelete]) {
            $foreignKey = $foreignKeys->get($column);
            Assert::assertNotNull($foreignKey, "{$table}.{$column} FK exists");
            Assert::assertSame($target, $foreignKey->table, "{$table}.{$column} target");
            Assert::assertSame('id', $foreignKey->to, "{$table}.{$column} target column");
            Assert::assertSame(
                $onDelete,
                $foreignKey->on_delete,
                $column === 'opened_by_device_id'
                    ? 'T3 gate: opened_by_device_id must SET NULL so the seating outlives its opening station'
                    : "{$table}.{$column} delete action",
            );
        }
    }

    foreach (tableSessionAddedColumns() as $table => $addedColumns) {
        $columns = collect(DB::select("PRAGMA table_info('{$table}')"))->keyBy('name');
        $foreignKeys = collect(DB::select("PRAGMA foreign_key_list('{$table}')"))->keyBy('from');
        foreach ($addedColumns as $column) {
            Assert::assertTrue($columns->has($column), "{$table}.{$column} exists");
            $required = $column === 'needs_review';
            Assert::assertSame((int) $required, (int) $columns->get($column)->notnull, "{$table}.{$column} nullability");
            if ($required) {
                Assert::assertSame('0', trim((string) $columns->get($column)->dflt_value, "'"));
            } else {
                Assert::assertNull($columns->get($column)->dflt_value, "{$table}.{$column} default");
            }
            if (in_array($column, ['table_session_id', 'origin_table_session_id'], true)) {
                $foreignKey = $foreignKeys->get($column);
                Assert::assertNotNull($foreignKey, "{$table}.{$column} FK exists");
                Assert::assertSame('pos_table_sessions', $foreignKey->table);
                Assert::assertSame('id', $foreignKey->to);
                Assert::assertSame('SET NULL', $foreignKey->on_delete);
            }
        }
    }

    Assert::assertFalse(Schema::hasTable('pos_table_session_sequences'));
}

/** @param list<string> $columns */
function assertTableSessionIndex(string $table, string $name, array $columns, int $unique, int $partial): void
{
    $indexes = collect(DB::select("PRAGMA index_list('{$table}')"))->keyBy('name');
    Assert::assertTrue($indexes->has($name), $name.' exists');
    Assert::assertSame($unique, (int) $indexes->get($name)->unique, $name.' uniqueness');
    Assert::assertSame($partial, (int) $indexes->get($name)->partial, $name.' partial predicate');
    Assert::assertSame(
        $columns,
        collect(DB::select("PRAGMA index_info('{$name}')"))->pluck('name')->all(),
        $name.' columns',
    );
}

function assertTableSessionIndexes(): void
{
    $indexes = [
        ['pos_table_sessions', 'pos_table_sessions_uuid_unique', ['uuid'], 1, 0],
        ['pos_table_sessions', 'pos_table_sessions_branch_status_idx', ['branch_id', 'status'], 0, 0],
        ['pos_table_sessions', 'pos_table_sessions_branch_temp_reference_idx', ['branch_id', 'temp_reference'], 0, 0],
        ['pos_table_sessions', 'pos_table_sessions_order_idx', ['order_id'], 0, 0],
        ['pos_table_sessions', 'pos_table_sessions_status_expires_idx', ['status', 'expires_at'], 0, 0],
        ['pos_table_sessions', 'pos_table_sessions_table_live_unique', ['table_id'], 1, 1],
        ['pos_table_sessions', 'pos_table_sessions_branch_request_unique', ['branch_id', 'client_request_id'], 1, 1],
        ['pos_table_session_events', 'pos_table_session_events_branch_cursor_idx', ['branch_id', 'id'], 0, 0],
        ['pos_table_session_events', 'pos_table_session_events_session_idx', ['table_session_id', 'id'], 0, 0],
        ['pos_qr_session_scans', 'pos_qr_session_scans_branch_scanned_idx', ['branch_id', 'scanned_at'], 0, 0],
        ['pos_qr_session_scans', 'pos_qr_session_scans_table_scanned_idx', ['table_id', 'scanned_at'], 0, 0],
        ['pos_kitchen_tickets', 'pos_kitchen_tickets_branch_key_unique', ['branch_id', 'ticket_key'], 1, 0],
        ['pos_qr_sessions', 'pos_qr_sessions_table_session_idx', ['table_session_id'], 0, 0],
        ['pos_qr_order_rounds', 'pos_qr_rounds_table_session_request_unique', ['table_session_id', 'client_request_id'], 1, 0],
        ['pos_orders', 'pos_orders_table_session_idx', ['table_session_id'], 0, 0],
        ['pos_qr_order_rounds', 'pos_qr_rounds_session_request_unique', ['qr_session_id', 'client_request_id'], 1, 0],
    ];
    foreach ($indexes as [$table, $name, $columns, $unique, $partial]) {
        assertTableSessionIndex($table, $name, $columns, $unique, $partial);
    }
}

/** @return array{company_id: int, branch_id: int, device_id: int, table_id: int} */
function tableSessionSchemaFixture(): array
{
    $branch = Branch::factory()->create();
    $device = Device::factory()->create(['company_id' => $branch->company_id, 'branch_id' => $branch->id]);
    $floorId = DB::table('pos_floors')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $branch->company_id,
        'branch_id' => $branch->id, 'name' => 'Schema floor',
    ]);
    $tableId = DB::table('pos_tables')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $branch->company_id,
        'floor_id' => $floorId, 'label' => 'Schema table', 'qr_token' => Str::random(64),
    ]);

    return [
        'company_id' => (int) $branch->company_id, 'branch_id' => (int) $branch->id,
        'device_id' => (int) $device->id, 'table_id' => (int) $tableId,
    ];
}

/** @param array<string, int> $scope */
function tableSessionSchemaSeating(array $scope, string $status, ?string $request = null): int
{
    return (int) DB::table('pos_table_sessions')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'], 'table_id' => $scope['table_id'],
        'status' => $status, 'origin' => 'station', 'client_request_id' => $request,
        'opened_at' => '2026-09-05 12:00:00', 'expires_at' => '2026-09-05 18:00:00',
    ]);
}

function assertTableSessionLiveAndRequestBehavior(): void
{
    $scope = tableSessionSchemaFixture();
    $live = tableSessionSchemaSeating($scope, 'open');
    foreach (['open', 'billing', 'closing'] as $status) {
        expect(fn () => tableSessionSchemaSeating($scope, $status))->toThrow(QueryException::class);
    }
    foreach (['closed', 'merged', 'expired'] as $status) {
        tableSessionSchemaSeating($scope, $status);
    }
    DB::table('pos_table_sessions')->where('id', $live)->update(['status' => 'closed']);
    tableSessionSchemaSeating($scope, 'open');
    tableSessionSchemaSeating($scope, 'closed', null);
    tableSessionSchemaSeating($scope, 'closed', null);
    tableSessionSchemaSeating($scope, 'closed', 'request-001');
    expect(fn () => tableSessionSchemaSeating($scope, 'closed', 'request-001'))->toThrow(QueryException::class);
    Assert::assertSame(8, DB::table('pos_table_sessions')->where('branch_id', $scope['branch_id'])->count());

    $otherBranch = tableSessionSchemaFixture();
    tableSessionSchemaSeating($otherBranch, 'closed', 'request-001');
    Assert::assertSame(1, DB::table('pos_table_sessions')->where('branch_id', $otherBranch['branch_id'])->count());
}

/** @param array<string, int> $scope */
function tableSessionSchemaQr(array $scope, string $status, bool $deviceLess = false): int
{
    return (int) DB::table('pos_qr_sessions')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'], 'device_id' => $deviceLess ? null : $scope['device_id'],
        'table_id' => $scope['table_id'], 'token' => Str::random(64),
        'token_expires_at' => '2026-09-05 18:00:00', 'status' => $status,
        'client_secret_hash' => hash('sha256', 'legacy-secret'), 'bound_at' => '2026-09-05 12:01:00',
        'expires_at' => '2026-09-05 18:00:00', 'closed_at' => $status === 'closed' ? '2026-09-05 13:00:00' : null,
        'created_at' => '2026-09-05 12:00:00', 'updated_at' => '2026-09-05 13:00:00',
    ]);
}

/** @param array<string, int> $scope */
function tableSessionSchemaOrder(array $scope, int $sessionId, string $status): int
{
    return (int) DB::table('pos_orders')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'], 'device_id' => $scope['device_id'],
        'table_id' => $scope['table_id'], 'qr_session_id' => $sessionId,
        'order_type' => 'dine_in', 'status' => $status, 'source' => 'qr_web',
        'receipt_number' => 'LEGACY-'.Str::random(12), 'temp_reference' => 'T-0905-001',
        'subtotal' => '1.234', 'tax_total' => '0.061', 'grand_total' => '1.295',
        'note' => 'unchanged legacy bytes', 'opened_at' => '2026-09-05 12:00:00',
        'closed_at' => '2026-09-05 13:00:00', 'created_at' => '2026-09-05 12:00:00',
        'updated_at' => '2026-09-05 13:00:00',
    ]);
}

function assertTableSessionLegacyIndexes(): void
{
    assertTableSessionIndex('pos_qr_sessions', 'pos_qr_sessions_table_live_unique', ['table_id'], 1, 1);
    assertTableSessionIndex('pos_orders', 'pos_orders_qr_session_live_unique', ['qr_session_id'], 1, 1);
    assertTableSessionIndex('pos_orders', 'pos_orders_qr_session_idx', ['qr_session_id'], 0, 1);
}

function assertTableSessionNullableDeviceAndLegacyBehavior(): void
{
    $columns = collect(DB::select("PRAGMA table_info('pos_qr_sessions')"))->keyBy('name');
    Assert::assertSame(0, (int) $columns->get('device_id')->notnull);
    $foreignKey = collect(DB::select("PRAGMA foreign_key_list('pos_qr_sessions')"))->firstWhere('from', 'device_id');
    Assert::assertNotNull($foreignKey);
    Assert::assertSame('pos_devices', $foreignKey->table);
    Assert::assertSame('id', $foreignKey->to);
    Assert::assertSame('CASCADE', $foreignKey->on_delete);
    assertTableSessionLegacyIndexes();

    $scope = tableSessionSchemaFixture();
    tableSessionSchemaQr($scope, 'closed');
    tableSessionSchemaQr($scope, 'closed');
    $live = tableSessionSchemaQr($scope, 'active');
    expect(fn () => tableSessionSchemaQr($scope, 'active'))->toThrow(QueryException::class);
    $deviceLess = tableSessionSchemaQr($scope, 'closed', true);
    Assert::assertNull(DB::table('pos_qr_sessions')->where('id', $deviceLess)->value('device_id'));
    Assert::assertSame(4, DB::table('pos_qr_sessions')->where('table_id', $scope['table_id'])->count());

    tableSessionSchemaOrder($scope, $live, 'paid');
    tableSessionSchemaOrder($scope, $live, 'refunded');
    $liveOrder = tableSessionSchemaOrder($scope, $live, 'open');
    expect(fn () => tableSessionSchemaOrder($scope, $live, 'awaiting_payment'))->toThrow(QueryException::class);
    DB::table('pos_orders')->where('id', $liveOrder)->update(['status' => 'paid']);
    tableSessionSchemaOrder($scope, $live, 'awaiting_payment');
    Assert::assertSame(4, DB::table('pos_orders')->where('qr_session_id', $live)->count());
    Assert::assertSame([], DB::select('PRAGMA foreign_key_check'));
}

it('owns the exact T2 seating columns defaults and foreign-key delete actions', function (): void {
    tableSessionSchemaDatabase(fn () => assertTableSessionColumnsAndForeignKeys());
});

it('owns every named seating index with its exact columns uniqueness and partial flags', function (): void {
    tableSessionSchemaDatabase(fn () => assertTableSessionIndexes());
});

it('enforces live seating and branch request idempotency without rejecting terminal or null-key rows', function (): void {
    tableSessionSchemaDatabase(fn () => assertTableSessionLiveAndRequestBehavior());
});

it('allows device-less credentials while preserving both legacy unique predicates and the partial lookup', function (): void {
    tableSessionSchemaDatabase(fn () => assertTableSessionNullableDeviceAndLegacyBehavior());
});

it('round-trips both T2 migrations without changing any pre-T2 QR order round or item column', function (): void {
    tableSessionSchemaDatabase(function (): void {
        $schema = require database_path('migrations/2026_09_05_010200_create_pos_table_session_schema.php');
        $widen = require database_path('migrations/2026_09_05_010300_make_pos_qr_sessions_device_nullable.php');
        $cards = require database_path('migrations/2026_09_07_010000_add_t9_card_columns.php');
        $scope = tableSessionSchemaFixture();
        $session = tableSessionSchemaQr($scope, 'closed');
        tableSessionSchemaQr($scope, 'closed');
        $order = tableSessionSchemaOrder($scope, $session, 'paid');
        tableSessionSchemaOrder($scope, $session, 'refunded');
        DB::table('pos_qr_order_rounds')->insert([
            'qr_session_id' => $session, 'order_id' => $order, 'round_no' => 1,
            'status' => 'confirmed', 'client_request_id' => 'legacy-round-001',
            'priced_lines' => '[{"name":"Legacy","quantity":1}]',
            'subtotal_baisas' => 1234, 'tax_baisas' => 61, 'total_baisas' => 1295,
            'submitted_at' => '2026-09-05 12:00:00', 'resolved_at' => '2026-09-05 12:01:00',
            'resolved_by_device_id' => $scope['device_id'],
            'created_at' => '2026-09-05 12:00:00', 'updated_at' => '2026-09-05 12:01:00',
        ]);
        DB::table('pos_order_items')->insert([
            'order_id' => $order, 'product_name_snapshot' => 'Legacy item',
            'qty' => '1.000', 'unit_price_snapshot' => '1.234', 'line_total' => '1.234',
            'recipe_snapshot_json' => '{"legacy":true}', 'notes' => 'preserve these bytes',
            'created_at' => '2026-09-05 12:00:00', 'updated_at' => '2026-09-05 12:01:00',
        ]);

        $columns = [];
        $before = [];
        foreach (tableSessionAddedColumns() as $table => $added) {
            $columns[$table] = array_values(array_diff(Schema::getColumnListing($table), $added));
            $before[$table] = DB::table($table)->orderBy('id')->get($columns[$table])
                ->map(static fn (object $row): array => (array) $row)->all();
        }
        Assert::assertSame(0, DB::transactionLevel());
        Assert::assertSame([], DB::select('PRAGMA foreign_key_check'));
        assertTableSessionLegacyIndexes();

        $cards->down();
        $widen->down();
        assertTableSessionLegacyIndexes();
        $schema->down();

        foreach (['pos_kitchen_tickets', 'pos_qr_session_scans', 'pos_table_session_events', 'pos_table_sessions'] as $table) {
            Assert::assertFalse(Schema::hasTable($table), $table.' removed');
        }
        foreach ($columns as $table => $legacyColumns) {
            Assert::assertSame($legacyColumns, Schema::getColumnListing($table), $table.' only pre-T2 columns after down');
            Assert::assertSame($before[$table], DB::table($table)->orderBy('id')->get($legacyColumns)
                ->map(static fn (object $row): array => (array) $row)->all(), $table.' every legacy value after down');
        }
        $device = collect(DB::select("PRAGMA table_info('pos_qr_sessions')"))->firstWhere('name', 'device_id');
        Assert::assertSame(1, (int) $device->notnull);
        expect(fn () => tableSessionSchemaQr($scope, 'closed', true))->toThrow(QueryException::class);
        assertTableSessionLegacyIndexes();
        Assert::assertSame([], DB::select('PRAGMA foreign_key_check'));

        $schema->up();
        assertTableSessionLegacyIndexes();
        $widen->up();
        assertTableSessionLegacyIndexes();
        foreach ($columns as $table => $legacyColumns) {
            Assert::assertSame($before[$table], DB::table($table)->orderBy('id')->get($legacyColumns)
                ->map(static fn (object $row): array => (array) $row)->all(), $table.' every legacy value after up');
        }
        Assert::assertSame(0, DB::table('pos_qr_order_rounds')->value('needs_review'));
        Assert::assertSame([], DB::select('PRAGMA foreign_key_check'));

        // Reapply the complete assertions of cases 1-4, not merely table existence.
        $cards->up();
        assertTableSessionColumnsAndForeignKeys();
        assertTableSessionIndexes();
        assertTableSessionLiveAndRequestBehavior();
        assertTableSessionNullableDeviceAndLegacyBehavior();
    });
});
