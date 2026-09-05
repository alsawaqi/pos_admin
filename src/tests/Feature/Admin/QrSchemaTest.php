<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

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
