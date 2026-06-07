<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Append-only ledger of (de)assignment events for a {@see Device}.
 *
 * Each row records: which device, which company + branch it lived at,
 * who triggered the change, when it opened, when it closed. Active
 * assignments have `unassigned_at = NULL`. This is the data backing
 * the "Assignment history" tab on the Device Detail page (blueprint
 * §4.4.4) and the source of truth for the admin audit trail of
 * device movement (§9.7.1).
 *
 * Insert is performed by {@see \App\Actions\Admin\AssignDeviceAction}.
 * Close-out is performed by {@see \App\Actions\Admin\UnassignDeviceAction}
 * (it stamps `unassigned_at`). Any other update is rejected at the
 * application layer — see {@see self::booted()}.
 */
class DeviceAssignmentHistory extends Model
{
    /**
     * The blueprint's history record only carries one "created" moment
     * (`assigned_at`); there is no concept of an updated_at on this
     * table because subsequent UPDATEs only ever stamp the close
     * timestamp. Surface `assigned_at` as Eloquent's created column so
     * `latest()` and `oldest()` keep working naturally.
     */
    public const CREATED_AT = 'assigned_at';
    public const UPDATED_AT = null;

    protected $table = 'pos_device_assignments_history';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'device_id',
        'company_id',
        'branch_id',
        'assigned_at',
        'unassigned_at',
        'assigned_by_admin_id',
        'unassign_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'unassigned_at' => 'datetime',
        ];
    }

    /**
     * Lock down mutations the app must never perform on this ledger.
     *
     * We INTENTIONALLY do not lock against UPDATE in general because
     * UnassignDeviceAction has to stamp `unassigned_at` + `unassign_reason`
     * on an existing row. Instead we trust the action to be the only
     * writer (no API endpoint hands out write access here) and forbid
     * DELETE outright so support cannot accidentally rewrite history.
     */
    protected static function booted(): void
    {
        static::deleting(static function (): never {
            throw new RuntimeException('Device assignment history rows are immutable.');
        });
    }

    /**
     * The device this assignment belongs to.
     *
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * The tenant the device was bound to during this assignment window.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The branch the device was bound to during this assignment window.
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The platform admin (a {@see User} row in `pos_users`) that triggered
     * the assignment. Nullable when the original actor has since been
     * removed from the system.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_admin_id');
    }
}
