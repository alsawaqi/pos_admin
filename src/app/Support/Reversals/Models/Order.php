<?php

declare(strict_types=1);

namespace App\Support\Reversals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Scalar-cast adapter for the reserved payment reversal engine; no portal enum casts. */
final class Order extends Model
{
    protected $table = 'pos_orders';

    protected $guarded = [];

    public const STATUS_VOID = 'void';

    public const STATUS_PAID = 'paid';

    public const STATUS_PENDING_VERIFICATION = 'pending_verification';

    public const STATUS_COMBINED = 'combined';

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    protected function casts(): array
    {
        return ['closed_at' => 'datetime', 'opened_at' => 'datetime'];
    }
}
