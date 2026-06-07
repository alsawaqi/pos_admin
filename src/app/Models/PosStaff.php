<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StaffPosition;
use App\Enums\StaffStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PIN-authenticated POS staff. Schema owned by this app's
 * 2026_05_27_010000_create_pos_staff_table migration.
 *
 * pos_admin doesn't manage staff CRUD (Phase 4.6 owns that on
 * the pos_merchant side), but exposes the model + relations so
 * future cross-merchant tooling (audit reports, support views)
 * can query staff rows without needing to redefine the shape.
 *
 * Writes from THIS app should go through dedicated Actions if
 * we ever need them — direct ::create from inside controllers
 * here would bypass the audit log that pos_merchant ships.
 */
#[Fillable([
    'uuid',
    'company_id',
    'branch_id',
    'name',
    'phone',
    'staff_code',
    'pin_hash',
    'position',
    'status',
    'hired_at',
    'terminated_at',
    'last_login_at',
    'created_by_user_id',
])]
#[Hidden(['pin_hash'])]
class PosStaff extends Model
{
    use SoftDeletes;

    protected $table = 'pos_staff';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => StaffPosition::class,
            'status' => StaffStatus::class,
            'hired_at' => 'date',
            'terminated_at' => 'datetime',
            'last_login_at' => 'datetime',
            'phone' => 'encrypted',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
