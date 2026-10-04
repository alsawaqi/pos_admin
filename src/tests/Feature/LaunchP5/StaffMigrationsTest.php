<?php

declare(strict_types=1);

/*
 * LAUNCH-P5 schema (pos_admin owns every migration; the data contract in
 * LAUNCH-P5_WORK_ORDER.md is shared with pos_api, pos_merchant, the till and
 * the handheld):
 *  - pos_staff: the offline verifier columns (pin_offline_key / _salt /
 *    _iterations);
 *  - pos_staff_branches: staff at several branches, back-filled with every
 *    staff member's home branch;
 *  - pos_approvals, pos_staff_attendance;
 *  - pos_orders voided_by / void_approved_by, the pos_shifts close and
 *    pay-out columns, pos_expenses.paid_from_drawer;
 *  - pos:check-tenant-integrity covers the new tables and columns.
 *
 * The suites run on SQLite; the Postgres-only CHECK constraints and composite
 * tenant foreign keys are verified in the live-copy rehearsal
 * (rehearse-p5.sh, must-fail SQL).
 */

use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use App\Models\PosStaff;
use App\Services\TenantIntegrityChecks;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function p5Staff(int $companyId, int $branchId, array $extra = []): int
{
    return (int) DB::table('pos_staff')->insertGetId($extra + [
        'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'branch_id' => $branchId, 'name' => 'Staff',
        'pin_hash' => 'not-a-real-hash', 'position' => 'cashier', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function p5PivotMigration(): object
{
    return require database_path('migrations/2026_10_04_100002_create_pos_staff_branches_table.php');
}

it('adds the offline verifier columns to pos_staff, empty for every existing row', function (): void {
    expect(Schema::hasColumns('pos_staff', ['pin_offline_key', 'pin_offline_salt', 'pin_offline_iterations']))->toBeTrue();

    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $row = DB::table('pos_staff')->find(p5Staff($company->id, $branch->id));

    expect($row->pin_offline_key)->toBeNull()
        ->and($row->pin_offline_salt)->toBeNull()
        ->and($row->pin_offline_iterations)->toBeNull();
});

it('never serialises the PIN hash or the offline verifier of a staff model', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $id = p5Staff($company->id, $branch->id, ['pin_offline_key' => str_repeat('ab', 32),
        'pin_offline_salt' => str_repeat('cd', 16), 'pin_offline_iterations' => 100000]);

    $array = PosStaff::query()->findOrFail($id)->toArray();

    expect($array)->not->toHaveKeys(['pin_hash', 'pin_offline_key', 'pin_offline_salt', 'pin_offline_iterations'])
        ->and($array)->toHaveKey('name');
});

it('back-fills every staff member\'s home branch into pos_staff_branches, trashed rows included', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $branchA = Branch::factory()->create(['company_id' => $a->id]);
    $branchA2 = Branch::factory()->create(['company_id' => $a->id]);
    $branchB = Branch::factory()->create(['company_id' => $b->id]);

    p5PivotMigration()->down();
    expect(Schema::hasTable('pos_staff_branches'))->toBeFalse();

    $one = p5Staff($a->id, $branchA->id);
    $two = p5Staff($a->id, $branchA2->id, ['status' => 'terminated', 'deleted_at' => now()]);
    $three = p5Staff($b->id, $branchB->id);
    // Bad legacy data: a home branch of another company is not back-filled
    // (the integrity check reports it instead of failing the deploy).
    $foreign = p5Staff($a->id, $branchB->id);

    p5PivotMigration()->up();

    $rows = DB::table('pos_staff_branches')->orderBy('staff_id')->get(['company_id', 'staff_id', 'branch_id'])
        ->map(fn ($r): array => [(int) $r->company_id, (int) $r->staff_id, (int) $r->branch_id])->all();
    expect($rows)->toBe([
        [$a->id, $one, $branchA->id],
        [$a->id, $two, $branchA2->id],
        [$b->id, $three, $branchB->id],
    ]);

    $gaps = app(TenantIntegrityChecks::class)->run()['staff_home_branch_missing'];
    expect($gaps)->toMatchArray(['count' => 1, 'sample_ids' => [$foreign], 'classification' => 'data_gap']);
});

it('keeps one row per staff member and branch', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $staff = p5Staff($company->id, $branch->id);
    DB::table('pos_staff_branches')->insert(['company_id' => $company->id, 'staff_id' => $staff, 'branch_id' => $branch->id,
        'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => DB::table('pos_staff_branches')->insert(['company_id' => $company->id, 'staff_id' => $staff,
        'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
});

it('adds the approvals and attendance tables and the void, shift and pay-out columns with today\'s meaning as default', function (): void {
    expect(Schema::hasColumns('pos_approvals', ['id', 'uuid', 'company_id', 'branch_id', 'device_id', 'client_event_id', 'action',
        'subject_type', 'subject_uuid', 'amount', 'ref', 'actor_staff_id', 'approver_staff_id', 'mode', 'method',
        'approved_at', 'verified_at', 'result', 'reason', 'created_at']))->toBeTrue()
        ->and(Schema::hasColumns('pos_staff_attendance', ['id', 'uuid', 'company_id', 'branch_id', 'staff_id', 'device_id',
            'clock_in_at', 'clock_out_at', 'source', 'edited_by_user_id', 'edit_reason', 'flags', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumns('pos_orders', ['voided_by_staff_id', 'void_approved_by_staff_id']))->toBeTrue()
        ->and(Schema::hasColumns('pos_shifts', ['closed_by_staff_id', 'close_device_id', 'needs_review', 'late_sales_baisas', 'payouts_baisas']))->toBeTrue()
        ->and(Schema::hasColumn('pos_expenses', 'paid_from_drawer'))->toBeTrue();

    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $shift = DB::table('pos_shifts')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
        'branch_id' => $branch->id, 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $expense = DB::table('pos_expenses')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
        'branch_id' => $branch->id, 'category' => 'supplies', 'amount' => '1.000', 'created_at' => now(), 'updated_at' => now()]);
    $order = DB::table('pos_orders')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
        'branch_id' => $branch->id, 'order_type' => 'quick', 'source' => 'main_pos', 'status' => 'paid', 'subtotal' => 1,
        'grand_total' => 1, 'opened_at' => now()]);

    $s = DB::table('pos_shifts')->find($shift);
    expect((bool) $s->needs_review)->toBeFalse()
        ->and((int) $s->late_sales_baisas)->toBe(0)
        ->and((int) $s->payouts_baisas)->toBe(0)
        ->and($s->closed_by_staff_id)->toBeNull()
        ->and($s->close_device_id)->toBeNull()
        ->and((bool) DB::table('pos_expenses')->where('id', $expense)->value('paid_from_drawer'))->toBeFalse()
        ->and(DB::table('pos_orders')->where('id', $order)->value('voided_by_staff_id'))->toBeNull()
        ->and(DB::table('pos_orders')->where('id', $order)->value('void_approved_by_staff_id'))->toBeNull();
});

it('checks the tenant of every new staff reference', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $branchA = Branch::factory()->create(['company_id' => $a->id]);
    $branchB = Branch::factory()->create(['company_id' => $b->id]);
    $device = Device::factory()->create(['company_id' => $a->id, 'branch_id' => $branchA->id]);
    $staffA = p5Staff($a->id, $branchA->id);
    $staffB = p5Staff($b->id, $branchB->id);
    foreach ([[$a->id, $staffA, $branchA->id], [$b->id, $staffB, $branchB->id]] as [$company, $staff, $branch]) {
        DB::table('pos_staff_branches')->insert(['company_id' => $company, 'staff_id' => $staff, 'branch_id' => $branch,
            'created_at' => now(), 'updated_at' => now()]);
    }
    $approval = fn (array $extra = []): int => (int) DB::table('pos_approvals')->insertGetId($extra + [
        'uuid' => (string) Str::uuid(), 'company_id' => $a->id, 'branch_id' => $branchA->id, 'device_id' => $device->id,
        'action' => 'comp', 'subject_type' => 'order', 'actor_staff_id' => $staffA, 'approver_staff_id' => $staffA,
        'mode' => 'approval', 'method' => 'offline', 'result' => 'verified', 'approved_at' => now(), 'created_at' => now(),
    ]);
    $attendance = fn (array $extra = []): int => (int) DB::table('pos_staff_attendance')->insertGetId($extra + [
        'uuid' => (string) Str::uuid(), 'company_id' => $a->id, 'branch_id' => $branchA->id, 'staff_id' => $staffA,
        'clock_in_at' => now(), 'source' => 'device', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $order = fn (array $extra = []): int => (int) DB::table('pos_orders')->insertGetId($extra + [
        'uuid' => (string) Str::uuid(), 'company_id' => $a->id, 'branch_id' => $branchA->id, 'order_type' => 'quick',
        'source' => 'main_pos', 'status' => 'void', 'subtotal' => 1, 'grand_total' => 1, 'opened_at' => now(),
    ]);
    $shift = fn (array $extra = []): int => (int) DB::table('pos_shifts')->insertGetId($extra + [
        'uuid' => (string) Str::uuid(), 'company_id' => $a->id, 'branch_id' => $branchA->id, 'opened_at' => now(),
        'status' => 'closed', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $approval();
    $attendance();
    $order(['voided_by_staff_id' => $staffA, 'void_approved_by_staff_id' => $staffA]);
    $shift(['closed_by_staff_id' => $staffA]);

    $names = ['staff_branch_company', 'approval_company', 'attendance_company', 'order_void_staff_company', 'shift_closer_company'];
    $clean = app(TenantIntegrityChecks::class)->run();
    foreach ($names as $name) {
        expect($clean[$name]['count'])->toBe(0, $name)
            ->and($clean[$name]['classification'])->toBe('violation');
    }
    expect($clean['staff_home_branch_missing']['count'])->toBe(0);

    $badPivot = (int) DB::table('pos_staff_branches')->insertGetId(['company_id' => $a->id, 'staff_id' => $staffA,
        'branch_id' => $branchB->id, 'created_at' => now(), 'updated_at' => now()]);
    $badApprover = $approval(['approver_staff_id' => $staffB]);
    $badAttendance = $attendance(['staff_id' => $staffB]);
    $badVoid = $order(['void_approved_by_staff_id' => $staffB]);
    $badShift = $shift(['closed_by_staff_id' => $staffB]);

    $results = app(TenantIntegrityChecks::class)->run();
    expect($results['staff_branch_company'])->toMatchArray(['count' => 1, 'sample_ids' => [$badPivot]])
        ->and($results['approval_company'])->toMatchArray(['count' => 1, 'sample_ids' => [$badApprover]])
        ->and($results['attendance_company'])->toMatchArray(['count' => 1, 'sample_ids' => [$badAttendance]])
        ->and($results['order_void_staff_company'])->toMatchArray(['count' => 1, 'sample_ids' => [$badVoid]])
        ->and($results['shift_closer_company'])->toMatchArray(['count' => 1, 'sample_ids' => [$badShift]]);
});
