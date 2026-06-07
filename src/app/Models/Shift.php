<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ShiftStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only mirror of pos_merchant's Shift. Schema owned by
 * pos_admin's 2026_06_04_020100 migration.
 *
 * Phase 7a — POS cashier shift.
 */
#[Fillable([])]
class Shift extends Model
{
    protected $table = 'pos_shifts';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ShiftStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'decimal:3',
            'closing_cash' => 'decimal:3',
            'expected_cash' => 'decimal:3',
            'variance' => 'decimal:3',
        ];
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
}
