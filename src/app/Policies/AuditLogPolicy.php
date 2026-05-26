<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PlatformPermission;
use App\Models\AuditLog;
use App\Models\User;

/**
 * Authorisation gate for the platform-wide audit log viewer
 * (blueprint §4.7).
 *
 * The audit log is **read-only** from this side — every row is written
 * exclusively by {@see \App\Actions\Security\WriteAuditLogAction} and
 * the model itself blocks `updating` + `deleting` events via its
 * booted() guard, so we never need create / update / delete policy
 * methods here.
 *
 * Both viewing and exporting collapse onto the single
 * {@see PlatformPermission::AuditLogsView} key. The blueprint treats
 * "see a row in the table" and "download a CSV of those rows" as the
 * same privilege — anyone allowed to read individual entries is
 * trusted to pull them in bulk. If finance ever needs export gated
 * separately we'd add an AuditLogsExport key and split here.
 *
 * Per blueprint §9.11.6, platform admins are deliberately omniscient
 * over audit data; there's no per-tenant scoping here on purpose.
 * The {@see AuthServiceProvider::grantSuperAdminEverything()}
 * Gate::before short-circuits Super Admin so they always pass these
 * checks regardless of the per-role permission pivot.
 */
class AuditLogPolicy
{
    /**
     * Whether the user can list audit log rows.
     *
     * Gates GET /admin/api/v1/audit-logs. Returns true iff the
     * user has the AuditLogsView permission, which is seeded for
     * every platform role today (Super Admin, Onboarding, Device
     * Operations, Support, Finance Viewer) — see
     * {@see \Database\Seeders\PlatformRoleSeeder::roleMatrix()}.
     */
    public function viewAny(User $user): bool
    {
        return $user->can(PlatformPermission::AuditLogsView->value);
    }

    /**
     * Whether the user can view an individual audit log entry.
     *
     * Not currently surfaced by any route (the SPA renders the
     * detail inline from the index response), but defined for
     * completeness so calls to $this->authorize('view', $log) from
     * a future detail endpoint work without code changes.
     */
    public function view(User $user, AuditLog $log): bool
    {
        return $user->can(PlatformPermission::AuditLogsView->value);
    }

    /**
     * Whether the user can stream a CSV of (filtered) audit rows.
     *
     * Gates GET /admin/api/v1/audit-logs/export.csv. Same key as
     * viewAny — see class docblock for why.
     */
    public function export(User $user): bool
    {
        return $user->can(PlatformPermission::AuditLogsView->value);
    }
}
