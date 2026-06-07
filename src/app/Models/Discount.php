<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DiscountAmountType;
use App\Enums\DiscountScope;
use App\Enums\DiscountStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Read-only mirror of pos_merchant's Discount. Schema owned
 * by pos_admin's 2026_06_05_010000 migration; writes happen
 * on the merchant side.
 *
 * Phase 6d — discount rule.
 */
#[Fillable([])]
class Discount extends Model
{
    use SoftDeletes;

    protected $table = 'pos_discounts';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => DiscountScope::class,
            'amount_type' => DiscountAmountType::class,
            'amount' => 'decimal:3',
            'validity_start' => 'datetime',
            'validity_end' => 'datetime',
            'dayofweek_mask' => 'integer',
            'branch_scope_json' => 'array',
            'stackable' => 'boolean',
            'requires_manager_approval' => 'boolean',
            'status' => DiscountStatus::class,
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
     * @return HasMany<DiscountTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(DiscountTarget::class);
    }
}
