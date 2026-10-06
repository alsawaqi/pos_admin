<?php

declare(strict_types=1);

/*
 * LAUNCH-P6 (customer tablet) — the data contract of
 * LAUNCH-P6_CUSTOMER_TABLET_WORK_ORDER.md §3 / §4 Part A item 3: pos_admin owns
 * the schema; pos_api writes pos_tablet_orders (one row per tablet submit,
 * with the points request, "Taken by" and "sent to the kitchen") and the
 * append-only pos_tablet_order_events audit.
 *
 * The suites run on SQLite; the Postgres-only CHECK constraints are proven by
 * the live-copy rehearsal's must-fail SQL (rehearse-p6.sh).
 */

use App\Models\Branch;
use App\Models\Company;
use App\Services\TenantIntegrityChecks;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function p6Migration(): object
{
    return require database_path('migrations/2026_10_06_120001_create_pos_tablet_orders_tables.php');
}

function p6Order(int $companyId, int $branchId, string $type = 'quick'): int
{
    return (int) DB::table('pos_orders')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $companyId,
        'branch_id' => $branchId, 'order_type' => $type, 'source' => 'customer_tablet', 'status' => 'held', 'subtotal' => '1.000',
        'tax_total' => '0.000', 'grand_total' => '1.000', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
}

function p6Staff(int $companyId, int $branchId): int
{
    return (int) DB::table('pos_staff')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $companyId,
        'branch_id' => $branchId, 'name' => 'Staff '.Str::random(4), 'pin_hash' => 'x', 'position' => 'cashier',
        'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
}

function p6Table(int $companyId, int $branchId): int
{
    $floor = (int) DB::table('pos_floors')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $companyId,
        'branch_id' => $branchId, 'name' => 'Floor', 'created_at' => now(), 'updated_at' => now()]);

    return (int) DB::table('pos_tables')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $companyId,
        'floor_id' => $floor, 'label' => 'T'.random_int(1, 999), 'qr_token' => hash('sha256', (string) Str::uuid()), 'created_at' => now(), 'updated_at' => now()]);
}

/** @param array<string, mixed> $extra */
function p6Row(int $companyId, int $branchId, int $orderId, array $extra = []): int
{
    return (int) DB::table('pos_tablet_orders')->insertGetId($extra + ['uuid' => (string) Str::uuid(), 'company_id' => $companyId,
        'branch_id' => $branchId, 'device_id' => null, 'client_uuid' => (string) Str::uuid(), 'order_id' => $orderId,
        'order_type' => 'quick', 'payment_choice' => 'cash', 'subtotal_baisas' => 1000, 'tax_baisas' => 0, 'total_baisas' => 1000,
        'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
}

/** @return array<string, list<int>> check => sample ids, for the checks that report something */
function p6Flagged(): array
{
    return collect(app(TenantIntegrityChecks::class)->run())->filter(fn (array $r, string $check): bool => $r['count'] > 0 && str_starts_with($check, 'tablet_'))
        ->map(fn (array $r): array => $r['sample_ids'])->all();
}

it('creates the tablet order and audit tables with the contract columns', function (): void {
    expect(Schema::hasColumns('pos_tablet_orders', ['id', 'uuid', 'company_id', 'branch_id', 'device_id', 'client_uuid',
        'order_id', 'round_id', 'table_id', 'order_type', 'payment_choice', 'customer_id', 'ready_in_minutes', 'subtotal_baisas',
        'tax_baisas', 'total_baisas', 'kitchen_lines', 'redeem_status', 'redeem_rule_id', 'redeem_blocks', 'redeem_units',
        'redeem_amount_baisas', 'redeem_discount_row_id', 'redeem_resolved_by_staff_id', 'redeem_resolved_by_device_id',
        'redeem_resolved_at', 'taken_by_staff_id', 'taken_by_device_id', 'taken_at', 'sent_to_kitchen_at', 'sent_by_staff_id',
        'sent_by_device_id', 'submitted_at', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('pos_tablet_order_events', ['id', 'company_id', 'branch_id', 'tablet_order_id', 'event_type',
            'staff_id', 'device_id', 'payload', 'created_at']))->toBeTrue();
});

it('keeps one row per tablet submit key', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $device = (int) DB::table('pos_devices')->insertGetId(['uuid' => (string) Str::uuid(), 'serial_number' => 'SN-P6',
        'name' => 'Tablet', 'device_type' => 'customer_tablet', 'company_id' => $company->id, 'branch_id' => $branch->id,
        'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    $key = (string) Str::uuid();
    p6Row($company->id, $branch->id, p6Order($company->id, $branch->id), ['device_id' => $device, 'client_uuid' => $key]);

    expect(fn () => p6Row($company->id, $branch->id, p6Order($company->id, $branch->id), ['device_id' => $device, 'client_uuid' => $key]))
        ->toThrow(QueryException::class);
    // Another tablet may use the same key.
    $other = (int) DB::table('pos_devices')->insertGetId(['uuid' => (string) Str::uuid(), 'serial_number' => 'SN-P6B',
        'name' => 'Tablet 2', 'device_type' => 'customer_tablet', 'company_id' => $company->id, 'branch_id' => $branch->id,
        'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    p6Row($company->id, $branch->id, p6Order($company->id, $branch->id), ['device_id' => $other, 'client_uuid' => $key]);
    expect(DB::table('pos_tablet_orders')->count())->toBe(2)->and(p6Flagged())->toBe([]);
});

it('reports a tablet row or event that reaches another merchant, branch or order', function (): void {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $otherBranch = Branch::factory()->for($other)->create();
    $sameMerchantBranch = Branch::factory()->for($company)->create();

    $clean = p6Row($company->id, $branch->id, p6Order($company->id, $branch->id), [
        'table_id' => p6Table($company->id, $branch->id), 'taken_by_staff_id' => p6Staff($company->id, $branch->id),
    ]);
    DB::table('pos_tablet_order_events')->insert(['company_id' => $company->id, 'branch_id' => $branch->id,
        'tablet_order_id' => $clean, 'event_type' => 'taken', 'created_at' => now()]);
    expect(p6Flagged())->toBe([]);

    $foreignOrder = p6Row($company->id, $branch->id, p6Order($other->id, $otherBranch->id));
    $otherBranchOrder = p6Row($company->id, $branch->id, p6Order($company->id, $sameMerchantBranch->id));
    $foreignTable = p6Row($company->id, $branch->id, p6Order($company->id, $branch->id), ['table_id' => p6Table($other->id, $otherBranch->id)]);
    $foreignStaff = p6Row($company->id, $branch->id, p6Order($company->id, $branch->id), ['sent_by_staff_id' => p6Staff($other->id, $otherBranch->id)]);
    $roundOrder = p6Order($company->id, $branch->id);
    $round = (int) DB::table('pos_qr_order_rounds')->insertGetId(['order_id' => $roundOrder, 'round_no' => 1, 'status' => 'accepted',
        'client_request_id' => (string) Str::uuid(), 'priced_lines' => '[]', 'subtotal_baisas' => 0, 'tax_baisas' => 0, 'total_baisas' => 0,
        'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $wrongRound = p6Row($company->id, $branch->id, p6Order($company->id, $branch->id), ['round_id' => $round]);
    $event = (int) DB::table('pos_tablet_order_events')->insertGetId(['company_id' => $company->id, 'branch_id' => $sameMerchantBranch->id,
        'tablet_order_id' => $clean, 'event_type' => 'taken_over', 'created_at' => now()]);

    $flagged = p6Flagged();
    expect($flagged['tablet_order_order'])->toEqualCanonicalizing([$foreignOrder, $otherBranchOrder])
        ->and($flagged['tablet_order_refs_company'])->toEqualCanonicalizing([$foreignTable, $foreignStaff])
        ->and($flagged['tablet_order_round'])->toBe([$wrongRound])
        ->and($flagged['tablet_order_event_company'])->toBe([$event]);
});

it('refuses to roll back once a tablet has ordered, and rolls back cleanly before', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $row = p6Row($company->id, $branch->id, p6Order($company->id, $branch->id));

    expect(fn () => p6Migration()->down())->toThrow(RuntimeException::class, 'Cannot roll back 2026_10_06_120001');
    expect(Schema::hasTable('pos_tablet_orders'))->toBeTrue();

    DB::table('pos_tablet_orders')->where('id', $row)->delete();
    p6Migration()->down();
    expect(Schema::hasTable('pos_tablet_orders'))->toBeFalse()->and(Schema::hasTable('pos_tablet_order_events'))->toBeFalse();
    p6Migration()->up();
    expect(Schema::hasTable('pos_tablet_orders'))->toBeTrue();
});
