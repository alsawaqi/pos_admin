<?php

declare(strict_types=1);

/*
 * LAUNCH-P6 fix order 1 — 2026_10_06_120002: pos_payments.staff_id (F-1, who
 * took the payment), the `superseded` points state (F-2) and the
 * `redeem_superseded` / `round_rejected` / `edited` audit events (F-2, F-6,
 * F-8). The Postgres CHECKs are proven by the rehearsal's must-fail SQL.
 */

use App\Models\Branch;
use App\Models\Company;
use App\Services\TenantIntegrityChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function p6f1Migration(): object
{
    return require database_path('migrations/2026_10_06_120002_add_payment_staff_and_tablet_states.php');
}

function p6f1Payment(int $orderId, ?int $staffId): int
{
    return (int) DB::table('pos_payments')->insertGetId(['uuid' => (string) Str::uuid(), 'order_id' => $orderId, 'method' => 'cash',
        'amount' => '1.000', 'status' => 'success', 'staff_id' => $staffId, 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
}

function p6f1Order(int $companyId, int $branchId): int
{
    return (int) DB::table('pos_orders')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $companyId,
        'branch_id' => $branchId, 'order_type' => 'quick', 'source' => 'main_pos', 'status' => 'paid', 'subtotal' => '1.000',
        'tax_total' => '0.000', 'grand_total' => '1.000', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
}

function p6f1Staff(int $companyId, int $branchId): int
{
    return (int) DB::table('pos_staff')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $companyId,
        'branch_id' => $branchId, 'name' => 'Staff '.Str::random(4), 'pin_hash' => 'x', 'position' => 'cashier',
        'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
}

it('adds who took a payment, empty on existing payments, and reports a payer of another merchant', function (): void {
    expect(Schema::hasColumn('pos_payments', 'staff_id'))->toBeTrue();
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $otherBranch = Branch::factory()->for($other)->create();
    $order = p6f1Order($company->id, $branch->id);

    $old = p6f1Payment($order, null);
    $own = p6f1Payment($order, p6f1Staff($company->id, $branch->id));
    expect(DB::table('pos_payments')->where('id', $old)->value('staff_id'))->toBeNull();
    $flagged = fn (): array => collect(app(TenantIntegrityChecks::class)->run())->get('payment_staff_company')['sample_ids'];
    expect($flagged())->toBe([]);

    $foreign = p6f1Payment($order, p6f1Staff($other->id, $otherBranch->id));
    expect($flagged())->toBe([$foreign])->and($own)->toBeGreaterThan(0);
});

it('takes the new tablet states and refuses to roll back once they or a payer are recorded', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->for($company)->create();
    $order = p6f1Order($company->id, $branch->id);
    $row = (int) DB::table('pos_tablet_orders')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
        'branch_id' => $branch->id, 'client_uuid' => (string) Str::uuid(), 'order_id' => $order, 'order_type' => 'quick',
        'payment_choice' => 'points', 'redeem_status' => 'superseded', 'redeem_blocks' => 1, 'subtotal_baisas' => 1000,
        'tax_baisas' => 0, 'total_baisas' => 1000, 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    foreach (['redeem_superseded', 'round_rejected', 'edited'] as $type) {
        DB::table('pos_tablet_order_events')->insert(['company_id' => $company->id, 'branch_id' => $branch->id,
            'tablet_order_id' => $row, 'event_type' => $type, 'created_at' => now()]);
    }

    expect(fn () => p6f1Migration()->down())->toThrow(RuntimeException::class, 'tablet orders use the new states');
    DB::table('pos_tablet_order_events')->delete();
    DB::table('pos_tablet_orders')->delete();
    $payment = p6f1Payment($order, p6f1Staff($company->id, $branch->id));
    expect(fn () => p6f1Migration()->down())->toThrow(RuntimeException::class, 'payments record the staff member');
    expect(Schema::hasColumn('pos_payments', 'staff_id'))->toBeTrue();

    DB::table('pos_payments')->where('id', $payment)->update(['staff_id' => null]);
    p6f1Migration()->down();
    expect(Schema::hasColumn('pos_payments', 'staff_id'))->toBeFalse();
    p6f1Migration()->up();
    expect(Schema::hasColumn('pos_payments', 'staff_id'))->toBeTrue();
});
