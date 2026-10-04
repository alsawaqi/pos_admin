<?php

declare(strict_types=1);

/*
 * LAUNCH-P5 follow-up 1 — pos_shifts.reopen_count (NOT NULL, default 0;
 * Postgres CHECK >= 0, proven by the rehearsal's must-fail insert). Devices
 * close a shift under UUID v5 of "shift-close:{shift_uuid}:{reopen_count}".
 */

use App\Models\Branch;
use App\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function p5ReopenMigration(): object
{
    return require database_path('migrations/2026_10_04_100009_add_reopen_count_to_pos_shifts.php');
}

it('adds reopen_count to pos_shifts at 0 for every existing and new shift', function (): void {
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    p5ReopenMigration()->down();
    expect(Schema::hasColumn('pos_shifts', 'reopen_count'))->toBeFalse();
    $existing = DB::table('pos_shifts')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
        'branch_id' => $branch->id, 'opened_at' => now(), 'status' => 'closed', 'created_at' => now(), 'updated_at' => now()]);

    p5ReopenMigration()->up();

    $new = DB::table('pos_shifts')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company->id,
        'branch_id' => $branch->id, 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    expect((int) DB::table('pos_shifts')->where('id', $existing)->value('reopen_count'))->toBe(0)
        ->and((int) DB::table('pos_shifts')->where('id', $new)->value('reopen_count'))->toBe(0);

    DB::table('pos_shifts')->where('id', $existing)->increment('reopen_count');
    expect((int) DB::table('pos_shifts')->where('id', $existing)->value('reopen_count'))->toBe(1);

    expect(fn () => DB::table('pos_shifts')->where('id', $new)->update(['reopen_count' => null]))->toThrow(QueryException::class);
});
