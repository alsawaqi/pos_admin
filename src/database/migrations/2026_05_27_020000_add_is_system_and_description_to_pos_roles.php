<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the spatie `pos_roles` table with two columns the
 * role-builder UI (Phase 4.8) needs:
 *
 *   is_system   — true on the rows the seeders create
 *                 (merchant_super_admin, merchant_manager, etc.
 *                 plus the platform-admin defaults). The UI
 *                 uses this to hide the delete button and lock
 *                 the name rename — a merchant SuperAdmin can
 *                 still re-shape which permissions a system
 *                 role holds, but can't remove it (the seeder
 *                 would re-create it) and can't rename it (the
 *                 string is the contract the role-name lookup
 *                 in CreateMerchantUserAction depends on).
 *
 *   description — free-text shown in the role table + editor
 *                 so admins can leave breadcrumbs for whoever
 *                 inherits the workspace ("This role is for
 *                 evening shift supervisors who close out the
 *                 till"). Optional.
 *
 * Both columns nullable to keep the migration safe against
 * existing rows; the seeder will backfill is_system=true on its
 * next run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_roles', function (Blueprint $table): void {
            // Default false — any role created via the UI is
            // user-managed (deletable, renameable). The seeder
            // overrides this to true when it (re)creates the
            // canonical defaults.
            $table->boolean('is_system')->default(false)->after('name');
            $table->text('description')->nullable()->after('is_system');
        });
    }

    public function down(): void
    {
        Schema::table('pos_roles', function (Blueprint $table): void {
            $table->dropColumn(['is_system', 'description']);
        });
    }
};
