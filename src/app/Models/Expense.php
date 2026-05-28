<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only mirror of pos_merchant's Expense. Schema owned by
 * pos_admin's 2026_06_07_010000 migration; writes happen on the
 * merchant side (and, in Phase 8, the POS sync feed).
 *
 * The admin app has no expense-review UI of its own — this
 * mirror exists so platform-level reporting (and the future
 * cross-tenant net-profit rollups) can read the table with the
 * proper enum casts.
 *
 * Phase 6 backfill — Expense (blueprint §5.10).
 */
#[Fillable([])]
class Expense extends Model
{
    protected $table = 'pos_expenses';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ExpenseCategory::class,
            'amount' => 'decimal:3',
            'logged_at' => 'datetime',
            'status' => ExpenseStatus::class,
            'reviewed_at' => 'datetime',
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
