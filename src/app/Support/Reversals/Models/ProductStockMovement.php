<?php

declare(strict_types=1);

namespace App\Support\Reversals\Models;

use Illuminate\Database\Eloquent\Model;

/** Scalar-cast adapter for the reserved payment reversal engine; no portal enum casts. */
final class ProductStockMovement extends Model
{
    protected $table = 'pos_product_stock_movements';

    protected $guarded = [];

    public $timestamps = false;

    public const TYPE_SALE_CONSUMPTION = 'sale_consumption';

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }
}
