<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PlatformPermission;
use App\Models\Device;
use App\Models\User;

/**
 * Authorisation rules for the Devices admin section.
 *
 * Each method maps to one of the granular permission keys defined in
 * {@see PlatformPermission}, which in turn are assigned to platform
 * roles by {@see \Database\Seeders\PlatformRoleSeeder}. The split is
 * intentional: a Support agent can VIEW devices (to help merchants
 * troubleshoot) but cannot REGISTER or ASSIGN them — that's
 * Device Operations + Super Admin only (blueprint §12).
 *
 * The Gate::before hook in {@see \App\Providers\AuthServiceProvider}
 * short-circuits every check for platform_super_admin, so these
 * methods only matter for the other four roles.
 */
class DevicePolicy
{
    /**
     * "List the devices fleet" → /admin/api/v1/devices index.
     */
    public function viewAny(User $user): bool
    {
        return $user->can(PlatformPermission::DevicesView->value);
    }

    /**
     * Read a single device record. Same permission as the list —
     * if you can list, you can drill in.
     */
    public function view(User $user, Device $device): bool
    {
        return $user->can(PlatformPermission::DevicesView->value);
    }

    /**
     * Add a brand-new device row keyed by the scalefusion kiosk id.
     * Restricted to Device Operations + Super Admin.
     */
    public function register(User $user): bool
    {
        return $user->can(PlatformPermission::DevicesRegister->value);
    }

    /**
     * Edit a registered device's identity + catalogue + commission /
     * organization bindings. Same authority as Register (Device Operations +
     * Super Admin) — it's the create power applied to an existing row.
     */
    public function update(User $user, Device $device): bool
    {
        return $user->can(PlatformPermission::DevicesRegister->value);
    }

    /**
     * Bind a registered device to a (company, branch). Same role
     * gating as Register. Reassignment uses the same permission —
     * the action handler decides whether to close out a prior
     * history row.
     */
    public function assign(User $user, Device $device): bool
    {
        return $user->can(PlatformPermission::DevicesAssign->value);
    }

    /**
     * Cut a device loose from its current branch (closes the open
     * history row, blanks the device's company_id / branch_id, and
     * leaves it ready to be re-assigned elsewhere).
     */
    public function unassign(User $user, Device $device): bool
    {
        return $user->can(PlatformPermission::DevicesUnassign->value);
    }

    /**
     * Permanent decommission. Reserved for Super Admin in practice
     * because it removes the device from the fleet entirely.
     */
    public function decommission(User $user, Device $device): bool
    {
        return $user->can(PlatformPermission::DevicesDecommission->value);
    }

    /**
     * Mint a one-shot activation code for an assigned device. The
     * Android cashier app exchanges the code for a long-lived
     * Sanctum PAT at first boot (Lane A — Android bridge).
     *
     * Allowed for DeviceOps + Super Admin. Refuses on unassigned
     * devices at the action layer (a code makes no sense before
     * the device knows which branch / company it belongs to).
     */
    public function issueActivationToken(User $user, Device $device): bool
    {
        return $user->can(PlatformPermission::DevicesActivate->value);
    }

    /**
     * Issue a live scalefusion command to the device (reboot, lock,
     * factory reset, wipe, ...). A sharper gate than view: Device
     * Operations + Super Admin only, and always audited.
     */
    public function control(User $user, Device $device): bool
    {
        return $user->can(PlatformPermission::DevicesControl->value);
    }
}
