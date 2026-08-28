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
        ]))->toBeTrue()
        ->and(Schema::hasColumns('pos_orders', [
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
        ->and(Schema::hasIndex('pos_payments', 'pos_payments_softpos_ref_idx'))->toBeTrue();
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
