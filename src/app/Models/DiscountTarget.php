<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DiscountTargetType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only mirror of pos_merchant's DiscountTarget. Schema
 * owned by pos_admin's 2026_06_05_010100 migration.
 *
 * Phase 6d — discount target row.
 */
#[Fillable([])]
class DiscountTarget extends Model
{
    protected $table = 'pos_discount_targets';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_type' => DiscountTargetType::class,
            'target_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Discount, $this>
     */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }
}
