<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P5 data contract — staff at several branches with one PIN (owner
 * decision 8).
 *
 * One row per (staff, branch) the person may work at. pos_staff.branch_id
 * stays as the "home branch" and must always be in this table; every existing
 * staff row is back-filled with its home branch here. Readers (pos_api login,
 * cashier and approver checks) also treat the home branch as included, so a
 * staff row written before the portal maintains this table still works.
 *
 * Tenant integrity: the row's company is the staff's and the branch's company.
 * On Postgres two composite foreign keys enforce it — (staff_id, company_id)
 * against pos_staff and (branch_id, company_id) against pos_branches, backed
 * by new UNIQUE (id, company_id) indexes on those two tables — so a pivot row
 * for another company's staff or branch is rejected by the database. SQLite
 * (the test mirror) has the plain foreign keys only; pos:check-tenant-integrity
 * checks both (staff_branch_company) and that every home branch is present
 * (staff_home_branch_missing). A home branch of another company (bad legacy
 * data) is left out of the back-fill and reported by that check instead of
 * failing the deploy.
 *
 * pos_api and pos_merchant mirror the table in their test schemas.
 */
return new class extends Migration
{
    public const STAFF_UNIQUE = 'pos_staff_id_company_unique';

    public const BRANCH_UNIQUE = 'pos_branches_id_company_unique';

    public function up(): void
    {
        Schema::create('pos_staff_branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('pos_staff')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['staff_id', 'branch_id'], 'pos_staff_branches_staff_branch_unique');
            $table->index(['company_id', 'branch_id'], 'pos_staff_branches_company_branch_idx');
        });

        DB::table('pos_staff_branches')->insertUsing(
            ['company_id', 'staff_id', 'branch_id', 'created_at', 'updated_at'],
            DB::table('pos_staff as s')
                ->join('pos_branches as b', function ($join): void {
                    $join->on('b.id', '=', 's.branch_id')->on('b.company_id', '=', 's.company_id');
                })
                ->orderBy('s.id')
                ->select(['s.company_id', 's.id', 's.branch_id'])
                ->selectRaw('CURRENT_TIMESTAMP as created_at, CURRENT_TIMESTAMP as updated_at'),
        );

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_staff" ADD CONSTRAINT "'.self::STAFF_UNIQUE.'" UNIQUE ("id", "company_id")');
            DB::statement('ALTER TABLE "pos_branches" ADD CONSTRAINT "'.self::BRANCH_UNIQUE.'" UNIQUE ("id", "company_id")');
            DB::statement('ALTER TABLE "pos_staff_branches" ADD CONSTRAINT "pos_staff_branches_staff_company_foreign"
                FOREIGN KEY ("staff_id", "company_id") REFERENCES "pos_staff" ("id", "company_id") ON DELETE CASCADE');
            DB::statement('ALTER TABLE "pos_staff_branches" ADD CONSTRAINT "pos_staff_branches_branch_company_foreign"
                FOREIGN KEY ("branch_id", "company_id") REFERENCES "pos_branches" ("id", "company_id") ON DELETE CASCADE');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_staff_branches');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_staff" DROP CONSTRAINT IF EXISTS "'.self::STAFF_UNIQUE.'"');
            DB::statement('ALTER TABLE "pos_branches" DROP CONSTRAINT IF EXISTS "'.self::BRANCH_UNIQUE.'"');
        }
    }
};
