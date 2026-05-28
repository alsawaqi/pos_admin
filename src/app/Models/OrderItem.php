<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderItemStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Read-only mirror of pos_merchant's OrderItem. Schema owned by
 * pos_admin's 2026_06_04_010100 migration.
 *
 * Phase 7a — single line on an order.
 */
#[Fillable([])]
class OrderItem extends Model
{
    protected $table = 'pos_order_items';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'unit_price_snapshot' => 'decimal:3',
            'line_discount' => 'decimal:3',
            'line_total' => 'decimal:3',
            'recipe_snapshot_json' => 'array',
            'status' => OrderItemStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return HasMany<OrderItemAddon, $this>
     */
    public function addons(): HasMany
    {
        return $this->hasMany(OrderItemAddon::class);
    }
}
