<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preserve the charity attribution that was true when the donation occurred.
 *
 * Devices and branches are mutable: a terminal can later move branches or be
 * assigned a different beneficiary/profile, and a branch can be renamed. The
 * retry path must forward the sale-time organization and branch label instead
 * of reconstructing them from today's records. Both columns are nullable so
 * rows written by older API deployments remain valid; there is no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        $addOrganizationId = ! Schema::hasColumn('pos_roundup_donations', 'organization_id');
        $addBranchName = ! Schema::hasColumn('pos_roundup_donations', 'branch_name');

        if (! $addOrganizationId && ! $addBranchName) {
            return;
        }

        Schema::table('pos_roundup_donations', function (Blueprint $table) use ($addOrganizationId, $addBranchName): void {
            if ($addOrganizationId) {
                $table->unsignedBigInteger('organization_id')->nullable();
            }

            if ($addBranchName) {
                $table->string('branch_name')->nullable();
            }
        });
    }

    public function down(): void
    {
        $dropOrganizationId = Schema::hasColumn('pos_roundup_donations', 'organization_id');
        $dropBranchName = Schema::hasColumn('pos_roundup_donations', 'branch_name');

        if (! $dropOrganizationId && ! $dropBranchName) {
            return;
        }

        Schema::table('pos_roundup_donations', function (Blueprint $table) use ($dropOrganizationId, $dropBranchName): void {
            if ($dropOrganizationId) {
                $table->dropColumn('organization_id');
            }

            if ($dropBranchName) {
                $table->dropColumn('branch_name');
            }
        });
    }
};
