<?php

declare(strict_types=1);

namespace App\Support\Reversals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Scalar-cast adapter for the reserved payment reversal engine; no portal enum casts. */
final class OrderItem extends Model
{
    protected $table = 'pos_order_items';

    protected $guarded = [];

    public const STATUS_VOID = 'void';

    public function addons(): HasMany
    {
        return $this->hasMany(OrderItemAddon::class, 'order_item_id');
    }

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'recipe_snapshot_json' => 'array', 'component_snapshot_json' => 'array'];
    }
}
