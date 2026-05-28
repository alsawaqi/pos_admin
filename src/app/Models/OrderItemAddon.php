<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only mirror of pos_merchant's OrderItemAddon. Schema
 * owned by pos_admin's 2026_06_04_010200 migration.
 *
 * Phase 7a — per-line add-on selection.
 */
#[Fillable([])]
class OrderItemAddon extends Model
{
    protected $table = 'pos_order_item_addons';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_delta_snapshot' => 'decimal:3',
            'ingredient_snapshot_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
