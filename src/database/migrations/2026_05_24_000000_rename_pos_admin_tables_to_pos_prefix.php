<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Rename every pos_admin_* data table to pos_*.
 *
 * The charity_db database we share with the charity app already owns a
 * `companies` table; we cannot reuse the unprefixed names. Standardising
 * on the shorter `pos_` prefix (instead of `pos_admin_`) keeps room for
 * pos_merchant to read these same tables later without the naming
 * implying admin-only ownership.
 *
 * Postgres tracks foreign keys by table OID, so renaming does NOT break
 * any FK relationship. Index and constraint names retain their original
 * `pos_admin_*` form — that is cosmetic and intentionally left alone.
 *
 * The Laravel migration-history table (pos_admin_migrations) is NOT
 * renamed — that one is genuinely per-app metadata.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $renames = [
        'pos_admin_users' => 'pos_users',
        'pos_admin_companies' => 'pos_companies',
        'pos_admin_branches' => 'pos_branches',
        'pos_admin_branch_user' => 'pos_branch_user',
        'pos_admin_devices' => 'pos_devices',
        'pos_admin_device_activation_tokens' => 'pos_device_activation_tokens',
        'pos_admin_audit_logs' => 'pos_audit_logs',
        'pos_admin_business_activities' => 'pos_business_activities',
        'pos_admin_company_activities' => 'pos_company_activities',
        'pos_admin_company_documents' => 'pos_company_documents',
        'pos_admin_company_status_history' => 'pos_company_status_history',
        'pos_admin_permissions' => 'pos_permissions',
        'pos_admin_roles' => 'pos_roles',
        'pos_admin_model_has_permissions' => 'pos_model_has_permissions',
        'pos_admin_model_has_roles' => 'pos_model_has_roles',
        'pos_admin_role_has_permissions' => 'pos_role_has_permissions',
    ];

    public function up(): void
    {
        foreach ($this->renames as $from => $to) {
            if (Schema::hasTable($from) && ! Schema::hasTable($to)) {
                Schema::rename($from, $to);
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->renames, true) as $from => $to) {
            if (Schema::hasTable($to) && ! Schema::hasTable($from)) {
                Schema::rename($to, $from);
            }
        }
    }
};
