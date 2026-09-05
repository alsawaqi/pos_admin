<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

uses(RefreshDatabase::class);

// SQLite rebuilds must run without RefreshDatabase's outer transaction so
// Laravel can temporarily disable FK enforcement without mutating child rows.
function qrRoundCredentialSchemaDatabase(Closure $assertions): void
{
    $name = 'qr003_t4_round_schema';
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

/** @return array<string, int> */
function qrRoundCredentialSchemaFixture(): array
{
    $branch = Branch::factory()->create();
    $device = Device::factory()->create(['company_id' => $branch->company_id, 'branch_id' => $branch->id]);
    $floor = DB::table('pos_floors')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $branch->company_id,
        'branch_id' => $branch->id, 'name' => 'Round schema floor',
    ]);
    $table = DB::table('pos_tables')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $branch->company_id,
        'floor_id' => $floor, 'label' => 'Round schema table', 'qr_token' => Str::random(64),
    ]);
    $session = DB::table('pos_qr_sessions')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $branch->company_id,
        'branch_id' => $branch->id, 'device_id' => $device->id, 'table_id' => $table,
        'token' => Str::random(64), 'token_expires_at' => '2026-09-05 18:00:00',
        'status' => 'closed', 'expires_at' => '2026-09-05 18:00:00',
    ]);
    $order = DB::table('pos_orders')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $branch->company_id,
        'branch_id' => $branch->id, 'table_id' => $table, 'qr_session_id' => $session,
        'order_type' => 'dine_in', 'status' => 'paid', 'source' => 'qr_web',
        'receipt_number' => 'KEEP-0001', 'temp_reference' => 'T-0905-001',
        'opened_at' => '2026-09-05 12:00:00', 'closed_at' => '2026-09-05 13:00:00',
        'created_at' => '2026-09-05 12:00:00', 'updated_at' => '2026-09-05 13:00:00',
    ]);
    $seating = DB::table('pos_table_sessions')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $branch->company_id,
        'branch_id' => $branch->id, 'table_id' => $table, 'order_id' => $order,
        'origin' => 'station', 'status' => 'closed', 'temp_reference' => 'T-0905-001',
        'opened_at' => '2026-09-05 12:00:00', 'expires_at' => '2026-09-05 18:00:00',
        'closed_at' => '2026-09-05 13:00:00', 'close_reason' => 'paid',
    ]);
    DB::table('pos_orders')->where('id', $order)->update(['table_session_id' => $seating]);
    DB::table('pos_qr_sessions')->where('id', $session)->update(['table_session_id' => $seating]);

    return [
        'company_id' => (int) $branch->company_id, 'branch_id' => (int) $branch->id,
        'device_id' => (int) $device->id, 'session_id' => (int) $session,
        'order_id' => (int) $order, 'seating_id' => (int) $seating,
    ];
}

/** @param array<string, int> $scope */
function qrRoundCredentialSchemaRound(array $scope, ?int $session, string $request, ?int $sequence = null): int
{
    return (int) DB::table('pos_qr_order_rounds')->insertGetId([
        'qr_session_id' => $session, 'table_session_id' => $scope['seating_id'],
        'order_id' => $scope['order_id'], 'round_no' => 1, 'status' => 'accepted',
        'client_request_id' => $request, 'priced_lines' => '[{"name":"Preserved round","qty":1}]',
        'subtotal_baisas' => 1200, 'tax_baisas' => 60, 'total_baisas' => 1260,
        'submitted_at' => '2026-09-05 12:00:00', 'resolved_at' => '2026-09-05 12:01:00',
        'resolved_by_device_id' => $scope['device_id'], 'accepted_seq' => $sequence,
        'created_at' => '2026-09-05 12:00:00', 'updated_at' => '2026-09-05 12:01:00',
    ]);
}

/** @return array<string, array{columns: list<string>, unique: int, partial: int}> */
function qrRoundCredentialSchemaIndexes(): array
{
    return collect(DB::select("PRAGMA index_list('pos_qr_order_rounds')"))
        ->mapWithKeys(static fn (object $index): array => [$index->name => [
            'columns' => collect(DB::select("PRAGMA index_info('{$index->name}')"))->pluck('name')->all(),
            'unique' => (int) $index->unique,
            'partial' => (int) $index->partial,
        ]])->sortKeys()->all();
}

/** @return array<string, array{table: string, to: string, on_delete: string}> */
function qrRoundCredentialSchemaForeignKeys(): array
{
    return collect(DB::select("PRAGMA foreign_key_list('pos_qr_order_rounds')"))
        ->mapWithKeys(static fn (object $key): array => [$key->from => [
            'table' => $key->table, 'to' => $key->to, 'on_delete' => $key->on_delete,
        ]])->sortKeys()->all();
}

/** @param list<string> $tables
 * @return array<string, list<array<string, mixed>>>
 */
function qrRoundCredentialSchemaRows(array $tables): array
{
    $rows = [];
    foreach ($tables as $table) {
        $rows[$table] = DB::table($table)->orderBy('id')->get()
            ->map(static fn (object $row): array => (array) $row)->all();
    }

    return $rows;
}

it('provides the QR session, order charge, and SoftPOS reference schema', function (): void {
    expect(Schema::hasTable('pos_qr_sessions'))->toBeTrue()
        ->and(Schema::hasColumns('pos_qr_sessions', [
            'id',
            'uuid',
            'company_id',
            'branch_id',
            'device_id',
            'token',
            'token_expires_at',
            'status',
            'client_secret_hash',
            'bound_at',
            'last_seen_at',
            'expires_at',
            'closed_at',
            'created_at',
            'updated_at',
            'table_session_id',
            'origin',
            'scan_fingerprint_hash',
            'scan_ip_hash',
            'scan_geofence_verdict',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('pos_orders', [
            'temp_reference',
            'table_session_id',
            'qr_session_id',
            'client_request_id',
            'charge_device_id',
            'charge_amount_baisas',
            'charge_roundup_amount_baisas',
            'charge_claimed_at',
            'charge_deadline_at',
            'charge_outcome',
        ]))->toBeTrue()
        ->and(Schema::hasIndex('pos_qr_sessions', 'pos_qr_sessions_uuid_unique'))->toBeTrue()
        ->and(Schema::hasIndex('pos_qr_sessions', 'pos_qr_sessions_token_unique'))->toBeTrue()
        ->and(Schema::hasIndex('pos_qr_sessions', 'pos_qr_sessions_device_status_idx'))->toBeTrue()
        ->and(Schema::hasIndex('pos_qr_sessions', 'pos_qr_sessions_status_expires_idx'))->toBeTrue()
        ->and(Schema::hasIndex('pos_orders', 'pos_orders_qr_session_live_unique'))->toBeTrue()
        ->and(Schema::hasIndex('pos_orders', 'pos_orders_qr_session_request_unique'))->toBeTrue()
        ->and(Schema::hasIndex('pos_orders', 'pos_orders_qr_session_idx'))->toBeTrue()
        ->and(Schema::hasIndex('pos_orders', 'pos_orders_status_charge_deadline_idx'))->toBeTrue()
        ->and(Schema::hasIndex('pos_payments', 'pos_payments_softpos_ref_idx'))->toBeTrue()
        ->and(Schema::hasIndex('pos_orders', 'pos_orders_branch_temp_reference_idx'))->toBeTrue()
        ->and(Schema::hasTable('pos_temp_reference_sequences'))->toBeTrue()
        ->and(Schema::hasIndex('pos_temp_reference_sequences', 'pos_temp_reference_sequences_scope_unique'))->toBeTrue();
});

it('keeps temporary references nullable and their required branch-day counter separate', function (): void {
    $orderColumns = collect(DB::select("PRAGMA table_info('pos_orders')"))->keyBy('name');
    $reference = $orderColumns->get('temp_reference');

    // SQLite reports varchar without its declared length; the migration is
    // explicitly string(..., 32). PostgreSQL enforces that length at deploy.
    expect($reference)->not->toBeNull()
        ->and(strtolower($reference->type))->toBe('varchar')
        ->and((int) $reference->notnull)->toBe(0)
        ->and($reference->dflt_value)->toBeNull();

    $orderIndexes = collect(DB::select("PRAGMA index_list('pos_orders')"))->keyBy('name');
    expect((int) $orderIndexes->get('pos_orders_branch_temp_reference_idx')->unique)->toBe(0)
        ->and(collect(DB::select("PRAGMA index_info('pos_orders_branch_temp_reference_idx')"))->pluck('name')->all())
        ->toBe(['branch_id', 'temp_reference']);

    $columns = collect(DB::select("PRAGMA table_info('pos_temp_reference_sequences')"))->keyBy('name');
    expect($columns->keys()->all())->toBe([
        'id', 'company_id', 'branch_id', 'seq_date', 'next_number', 'created_at', 'updated_at',
    ]);
    foreach (['company_id', 'branch_id', 'seq_date'] as $column) {
        expect((int) $columns->get($column)->notnull)->toBe(1);
    }
    expect(trim((string) $columns->get('next_number')->dflt_value, "'"))->toBe('1');

    $indexes = collect(DB::select("PRAGMA index_list('pos_temp_reference_sequences')"))->keyBy('name');
    expect((int) $indexes->get('pos_temp_reference_sequences_scope_unique')->unique)->toBe(1)
        ->and(collect(DB::select("PRAGMA index_info('pos_temp_reference_sequences_scope_unique')"))->pluck('name')->all())
        ->toBe(['company_id', 'branch_id', 'seq_date']);

    $foreignKeys = collect(DB::select("PRAGMA foreign_key_list('pos_temp_reference_sequences')"))->keyBy('from');
    expect($foreignKeys)->toHaveCount(2);
    foreach (['company_id' => 'pos_companies', 'branch_id' => 'pos_branches'] as $column => $table) {
        expect($foreignKeys->get($column)->table)->toBe($table)
            ->and($foreignKeys->get($column)->to)->toBe('id')
            ->and($foreignKeys->get($column)->on_delete)->toBe('CASCADE');
    }

    $branch = Branch::factory()->create();
    $row = ['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'seq_date' => '2026-09-05'];
    DB::table('pos_temp_reference_sequences')->insert($row);
    expect((int) DB::table('pos_temp_reference_sequences')->value('next_number'))->toBe(1)
        ->and(fn () => DB::table('pos_temp_reference_sequences')->insert($row))->toThrow(QueryException::class)
        ->and(DB::table('pos_order_sequences')->count())->toBe(0);
});

it('rolls back and reapplies only the temporary reference schema without backfilling existing orders', function (): void {
    $branch = Branch::factory()->create();
    $orderId = DB::table('pos_orders')->insertGetId([
        'uuid' => '30000000-0000-4000-8000-000000000001',
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'order_type' => 'quick',
        'status' => 'paid',
        'source' => 'qr_web',
        'receipt_number' => 'LEGACY-0001',
        'temp_reference' => 'T-0905-001',
        'opened_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_09_05_010100_add_temp_reference_to_pos_orders.php');

    $migration->down();

    expect(Schema::hasTable('pos_temp_reference_sequences'))->toBeFalse()
        ->and(Schema::hasIndex('pos_orders', 'pos_orders_branch_temp_reference_idx'))->toBeFalse()
        ->and(Schema::hasColumn('pos_orders', 'temp_reference'))->toBeFalse()
        ->and(Schema::hasTable('pos_order_sequences'))->toBeTrue()
        ->and(DB::table('pos_orders')->where('id', $orderId)->value('receipt_number'))->toBe('LEGACY-0001');

    $migration->up();

    expect(Schema::hasTable('pos_temp_reference_sequences'))->toBeTrue()
        ->and(Schema::hasIndex('pos_orders', 'pos_orders_branch_temp_reference_idx'))->toBeTrue()
        ->and(Schema::hasColumn('pos_orders', 'temp_reference'))->toBeTrue()
        ->and(DB::table('pos_temp_reference_sequences')->count())->toBe(0)
        ->and(DB::table('pos_orders')->where('id', $orderId)->value('temp_reference'))->toBeNull()
        ->and(DB::table('pos_orders')->where('id', $orderId)->value('receipt_number'))->toBe('LEGACY-0001')
        ->and(DB::table('pos_orders')->count())->toBe(1);
});

it('allows only one non-terminal order per QR session and releases terminal orders', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $device = Device::factory()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
    ]);

    $sessionId = DB::table('pos_qr_sessions')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'device_id' => $device->id,
        'token' => Str::random(64),
        'token_expires_at' => now()->addMinute(),
        'status' => 'pending',
        'expires_at' => now()->addMinute(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $orderRow = static fn (string $status): array => [
        'uuid' => (string) Str::uuid(),
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'order_type' => 'quick',
        'status' => $status,
        'source' => 'qr_web',
        'qr_session_id' => $sessionId,
        'opened_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $firstOrderId = DB::table('pos_orders')->insertGetId($orderRow('awaiting_payment'));

    // Keep the connection usable after PostgreSQL's constraint error by
    // containing the expected failure in a nested transaction/savepoint.
    $baseTransactionLevel = DB::transactionLevel();
    DB::beginTransaction();
    $duplicateRejected = false;
    try {
        DB::table('pos_orders')->insert($orderRow('open'));
    } catch (QueryException) {
        $duplicateRejected = true;
    } finally {
        while (DB::transactionLevel() > $baseTransactionLevel) {
            DB::rollBack();
        }
    }

    expect($duplicateRejected)->toBeTrue();

    DB::table('pos_orders')->where('id', $firstOrderId)->update(['status' => 'paid']);
    DB::table('pos_orders')->insert($orderRow('open'));

    expect(DB::table('pos_orders')->where('qr_session_id', $sessionId)->count())->toBe(2);
});

it('scopes client request id uniqueness to one QR session', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $device = Device::factory()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
    ]);

    $sessionRow = static fn (): array => [
        'uuid' => (string) Str::uuid(),
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'device_id' => $device->id,
        'token' => Str::random(64),
        'token_expires_at' => now()->addMinute(),
        'status' => 'pending',
        'expires_at' => now()->addMinute(),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $firstSessionId = DB::table('pos_qr_sessions')->insertGetId($sessionRow());
    $secondSessionId = DB::table('pos_qr_sessions')->insertGetId($sessionRow());

    $orderRow = static fn (int $sessionId, ?string $requestId): array => [
        'uuid' => (string) Str::uuid(),
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'order_type' => 'quick',
        // Terminal on purpose: P1's live-session partial unique must not
        // be the constraint that rejects the duplicate request below.
        'status' => 'paid',
        'source' => 'qr_web',
        'qr_session_id' => $sessionId,
        'client_request_id' => $requestId,
        'opened_at' => now(),
        'closed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('pos_orders')->insert($orderRow($firstSessionId, 'request-001'));

    $baseTransactionLevel = DB::transactionLevel();
    DB::beginTransaction();
    $duplicateRejected = false;
    try {
        DB::table('pos_orders')->insert($orderRow($firstSessionId, 'request-001'));
    } catch (QueryException) {
        $duplicateRejected = true;
    } finally {
        while (DB::transactionLevel() > $baseTransactionLevel) {
            DB::rollBack();
        }
    }

    expect($duplicateRejected)->toBeTrue();

    DB::table('pos_orders')->insert($orderRow($firstSessionId, 'request-002'));
    DB::table('pos_orders')->insert($orderRow($secondSessionId, 'request-001'));
    DB::table('pos_orders')->insert($orderRow($firstSessionId, null));
    DB::table('pos_orders')->insert($orderRow($firstSessionId, null));

    expect(DB::table('pos_orders')->count())->toBe(5);
});

it('allows credential-free T4 rounds while retaining all round foreign keys and unique indexes', function (): void {
    qrRoundCredentialSchemaDatabase(function (): void {
        $columns = collect(DB::select("PRAGMA table_info('pos_qr_order_rounds')"))->keyBy('name');
        expect((int) $columns->get('qr_session_id')->notnull)->toBe(0)
            ->and($columns->get('qr_session_id')->dflt_value)->toBeNull()
            ->and(qrRoundCredentialSchemaForeignKeys())->toBe([
                'order_id' => ['table' => 'pos_orders', 'to' => 'id', 'on_delete' => 'SET NULL'],
                'origin_table_session_id' => ['table' => 'pos_table_sessions', 'to' => 'id', 'on_delete' => 'SET NULL'],
                'qr_session_id' => ['table' => 'pos_qr_sessions', 'to' => 'id', 'on_delete' => 'CASCADE'],
                'resolved_by_device_id' => ['table' => 'pos_devices', 'to' => 'id', 'on_delete' => 'SET NULL'],
                'table_session_id' => ['table' => 'pos_table_sessions', 'to' => 'id', 'on_delete' => 'SET NULL'],
            ])
            ->and(qrRoundCredentialSchemaIndexes())->toBe([
                'pos_qr_order_rounds_accepted_seq_unique' => ['columns' => ['accepted_seq'], 'unique' => 1, 'partial' => 0],
                'pos_qr_rounds_order_status_idx' => ['columns' => ['order_id', 'status'], 'unique' => 0, 'partial' => 0],
                'pos_qr_rounds_session_request_unique' => ['columns' => ['qr_session_id', 'client_request_id'], 'unique' => 1, 'partial' => 0],
                'pos_qr_rounds_table_session_request_unique' => ['columns' => ['table_session_id', 'client_request_id'], 'unique' => 1, 'partial' => 0],
            ]);

        $first = qrRoundCredentialSchemaFixture();
        $second = qrRoundCredentialSchemaFixture();
        qrRoundCredentialSchemaRound($first, null, 'staff-request', 1);
        qrRoundCredentialSchemaRound($second, null, 'staff-request', 2);
        expect(fn () => qrRoundCredentialSchemaRound($first, null, 'staff-request', 3))->toThrow(QueryException::class)
            ->and(fn () => qrRoundCredentialSchemaRound($first, null, 'different-request', 1))->toThrow(QueryException::class);

        // Distinct seating ids ensure the credential-key index itself rejects
        // this duplicate, not the independent seating-key unique index.
        qrRoundCredentialSchemaRound($first, $first['session_id'], 'qr-request');
        expect(fn () => qrRoundCredentialSchemaRound($second, $first['session_id'], 'qr-request'))->toThrow(QueryException::class)
            ->and(fn () => qrRoundCredentialSchemaRound($first, PHP_INT_MAX, 'missing-credential'))->toThrow(QueryException::class)
            ->and(DB::table('pos_qr_order_rounds')->count())->toBe(3)
            ->and(DB::select('PRAGMA foreign_key_check'))->toBe([]);
    });
});

it('still cascades credential deletion only to credentialed rounds after T4 widening', function (): void {
    qrRoundCredentialSchemaDatabase(function (): void {
        $scope = qrRoundCredentialSchemaFixture();
        $credentialed = qrRoundCredentialSchemaRound($scope, $scope['session_id'], 'credentialed');
        $staff = qrRoundCredentialSchemaRound($scope, null, 'staff');
        $staffBefore = (array) DB::table('pos_qr_order_rounds')->where('id', $staff)->first();
        $seatingBefore = qrRoundCredentialSchemaRows(['pos_table_sessions']);
        DB::table('pos_kitchen_tickets')->insert([
            'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'],
            'ticket_key' => 'round:'.$credentialed, 'round_id' => $credentialed,
            'order_id' => $scope['order_id'], 'claimed_by_device_id' => $scope['device_id'],
            'claimed_at' => '2026-09-05 12:02:00',
        ]);

        DB::table('pos_qr_sessions')->where('id', $scope['session_id'])->delete();

        expect(DB::table('pos_qr_order_rounds')->where('id', $credentialed)->exists())->toBeFalse()
            ->and((array) DB::table('pos_qr_order_rounds')->where('id', $staff)->first())->toBe($staffBefore)
            ->and(qrRoundCredentialSchemaRows(['pos_table_sessions']))->toBe($seatingBefore)
            ->and(DB::table('pos_orders')->count())->toBe(1)
            ->and(DB::table('pos_orders')->value('qr_session_id'))->toBeNull()
            ->and(DB::table('pos_kitchen_tickets')->count())->toBe(1)
            ->and(DB::table('pos_kitchen_tickets')->value('round_id'))->toBeNull()
            ->and(DB::select('PRAGMA foreign_key_check'))->toBe([]);
    });
});

it('round-trips T4 nullable credentials preserving legacy bytes and deleting only null-credential rounds on rollback', function (): void {
    qrRoundCredentialSchemaDatabase(function (): void {
        $migration = require database_path('migrations/2026_09_05_010400_make_pos_qr_order_rounds_session_nullable.php');
        $migration->down();
        $column = collect(DB::select("PRAGMA table_info('pos_qr_order_rounds')"))->firstWhere('name', 'qr_session_id');
        expect((int) $column->notnull)->toBe(1);

        $scope = qrRoundCredentialSchemaFixture();
        $credentialed = qrRoundCredentialSchemaRound($scope, $scope['session_id'], 'legacy-request', 1);
        DB::table('pos_kitchen_tickets')->insert([
            'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'],
            'ticket_key' => 'round:'.$credentialed, 'round_id' => $credentialed,
            'order_id' => $scope['order_id'], 'claimed_by_device_id' => $scope['device_id'],
            'claimed_at' => '2026-09-05 12:02:00', 'printed_at' => '2026-09-05 12:03:00',
            'print_result' => 'printed',
        ]);
        $preserved = ['pos_qr_sessions', 'pos_orders', 'pos_table_sessions', 'pos_qr_order_rounds', 'pos_kitchen_tickets'];
        $before = qrRoundCredentialSchemaRows($preserved);
        $indexes = qrRoundCredentialSchemaIndexes();
        $foreignKeys = qrRoundCredentialSchemaForeignKeys();
        $partialIndexes = DB::table('sqlite_master')->where('type', 'index')->where('sql', 'like', '%WHERE%')
            ->orderBy('name')->pluck('sql', 'name')->all();

        $migration->up();

        expect((int) collect(DB::select("PRAGMA table_info('pos_qr_order_rounds')"))->firstWhere('name', 'qr_session_id')->notnull)->toBe(0)
            ->and(qrRoundCredentialSchemaRows($preserved))->toBe($before)
            ->and(qrRoundCredentialSchemaIndexes())->toBe($indexes)
            ->and(qrRoundCredentialSchemaForeignKeys())->toBe($foreignKeys);
        $staff = qrRoundCredentialSchemaRound($scope, null, 'staff-request', 2);
        DB::table('pos_kitchen_tickets')->insert([
            'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'],
            'ticket_key' => 'round:'.$staff, 'round_id' => $staff,
            'order_id' => $scope['order_id'], 'claimed_by_device_id' => $scope['device_id'],
            'claimed_at' => '2026-09-05 12:04:00',
        ]);

        $migration->down();

        expect((int) collect(DB::select("PRAGMA table_info('pos_qr_order_rounds')"))->firstWhere('name', 'qr_session_id')->notnull)->toBe(1)
            ->and(DB::table('pos_qr_order_rounds')->where('id', $staff)->exists())->toBeFalse()
            ->and(DB::table('pos_kitchen_tickets')->count())->toBe(2)
            ->and(DB::table('pos_kitchen_tickets')->where('ticket_key', 'round:'.$staff)->value('round_id'))->toBeNull()
            ->and((array) DB::table('pos_kitchen_tickets')->where('ticket_key', 'round:'.$credentialed)->first())
            ->toBe($before['pos_kitchen_tickets'][0])
            ->and(fn () => qrRoundCredentialSchemaRound($scope, null, 'not-null-enforced'))->toThrow(QueryException::class);
        unset($before['pos_kitchen_tickets']);
        expect(qrRoundCredentialSchemaRows(array_keys($before)))->toBe($before)
            ->and(qrRoundCredentialSchemaIndexes())->toBe($indexes)
            ->and(qrRoundCredentialSchemaForeignKeys())->toBe($foreignKeys)
            ->and(DB::table('sqlite_master')->where('type', 'index')->where('sql', 'like', '%WHERE%')
                ->orderBy('name')->pluck('sql', 'name')->all())->toBe($partialIndexes)
            ->and(DB::select('PRAGMA foreign_key_check'))->toBe([]);

        $migration->up();

        expect((int) collect(DB::select("PRAGMA table_info('pos_qr_order_rounds')"))->firstWhere('name', 'qr_session_id')->notnull)->toBe(0)
            ->and(qrRoundCredentialSchemaRows(array_keys($before)))->toBe($before)
            ->and(qrRoundCredentialSchemaIndexes())->toBe($indexes)
            ->and(qrRoundCredentialSchemaForeignKeys())->toBe($foreignKeys)
            ->and(DB::select('PRAGMA foreign_key_check'))->toBe([]);
    });
});
