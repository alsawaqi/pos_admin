<?php

declare(strict_types=1);

/*
 * LAUNCH-P5 fix order 1 — the three new columns (pos_admin owns them; pos_api
 * and pos_merchant mirror them):
 *  - pos_devices.auth_v_seen_at (F2, the sticky P5 marker), NULL until seen;
 *  - pos_expenses.shift_id (F6, the drawer shift of a pay-out), NULL for
 *    existing rows; Postgres FK to pos_shifts ON DELETE SET NULL;
 *  - pos_shifts.late_payouts_baisas (F7), 0 for existing rows; Postgres
 *    CHECK >= 0.
 * The Postgres FK and CHECK are proven by the live-copy rehearsal's must-fail
 * SQL (rehearse-p5.sh); pos:check-tenant-integrity covers the expense's shift.
 */

use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use App\Services\TenantIntegrityChecks;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function p5f1Migration(string $name): object
{
    return require database_path('migrations/'.$name.'.php');
}

function p5f1Shift(int $companyId, int $branchId, array $extra = []): int
{
    return (int) DB::table('pos_shifts')->insertGetId($extra + ['uuid' => (string) Str::uuid(), 'company_id' => $companyId,
        'branch_id' => $branchId, 'opened_at' => now(), 'status' => 'closed', 'created_at' => now(), 'updated_at' => now()]);
}

function p5f1Expense(int $companyId, int $branchId, array $extra = []): int
{
    return (int) DB::table('pos_expenses')->insertGetId($extra + ['uuid' => (string) Str::uuid(), 'company_id' => $companyId,
        'branch_id' => $branchId, 'category' => 'supplies', 'amount' => '2.500', 'created_at' => now(), 'updated_at' => now()]);
}

it('adds pos_devices.auth_v_seen_at, empty for every existing device', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $migration = p5f1Migration('2026_10_04_100010_add_auth_v_seen_at_to_pos_devices');
    $migration->down();
    expect(Schema::hasColumn('pos_devices', 'auth_v_seen_at'))->toBeFalse();
    $device = Device::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id]);

    $migration->up();

    expect(DB::table('pos_devices')->where('id', $device->id)->value('auth_v_seen_at'))->toBeNull();
    DB::table('pos_devices')->where('id', $device->id)->update(['auth_v_seen_at' => now()]);
    expect(DB::table('pos_devices')->where('id', $device->id)->value('auth_v_seen_at'))->not->toBeNull();
});

it('adds pos_expenses.shift_id, empty for existing rows, and checks its company', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $branchA = Branch::factory()->create(['company_id' => $a->id]);
    $branchB = Branch::factory()->create(['company_id' => $b->id]);
    $migration = p5f1Migration('2026_10_04_100011_add_shift_id_to_pos_expenses');
    $migration->down();
    expect(Schema::hasColumn('pos_expenses', 'shift_id'))->toBeFalse();
    $existing = p5f1Expense($a->id, $branchA->id, ['paid_from_drawer' => true]);

    $migration->up();

    expect(DB::table('pos_expenses')->where('id', $existing)->value('shift_id'))->toBeNull();
    $shiftA = p5f1Shift($a->id, $branchA->id);
    $shiftB = p5f1Shift($b->id, $branchB->id);
    p5f1Expense($a->id, $branchA->id, ['paid_from_drawer' => true, 'shift_id' => $shiftA]);

    $clean = app(TenantIntegrityChecks::class)->run();
    expect($clean['expense_shift_company']['count'])->toBe(0)
        ->and($clean['expense_shift_company']['classification'])->toBe('violation');

    $bad = p5f1Expense($a->id, $branchA->id, ['paid_from_drawer' => true, 'shift_id' => $shiftB]);
    expect(app(TenantIntegrityChecks::class)->run()['expense_shift_company'])->toMatchArray(['count' => 1, 'sample_ids' => [$bad]]);
});

it('adds pos_shifts.late_payouts_baisas at 0 for every existing and new shift', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $migration = p5f1Migration('2026_10_04_100012_add_late_payouts_to_pos_shifts');
    $migration->down();
    expect(Schema::hasColumn('pos_shifts', 'late_payouts_baisas'))->toBeFalse();
    $existing = p5f1Shift($company->id, $branch->id);

    $migration->up();

    $new = p5f1Shift($company->id, $branch->id, ['status' => 'open']);
    expect((int) DB::table('pos_shifts')->where('id', $existing)->value('late_payouts_baisas'))->toBe(0)
        ->and((int) DB::table('pos_shifts')->where('id', $new)->value('late_payouts_baisas'))->toBe(0);
    DB::table('pos_shifts')->where('id', $existing)->increment('late_payouts_baisas', 2500);
    expect((int) DB::table('pos_shifts')->where('id', $existing)->value('late_payouts_baisas'))->toBe(2500);
    expect(fn () => DB::table('pos_shifts')->where('id', $new)->update(['late_payouts_baisas' => null]))->toThrow(QueryException::class);
});
