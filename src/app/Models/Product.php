<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProductStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * `pos_products` row. Read-only on pos_admin; pos_merchant
 * handles CRUD via Phase 6 actions.
 */
#[Fillable([])]
class Product extends Model
{
    use SoftDeletes;

    protected $table = 'pos_products';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:3',
            // Phase 4.9 — per-product delivery override. NULL
            // means inherit base_price for delivery orders.
            'delivery_price' => 'decimal:3',
            'cost_price' => 'decimal:3',
            'tax_rate' => 'decimal:2',
            'display_order' => 'integer',
            'status' => ProductStatus::class,
        ];
    }

    /**
     * @return BelongsTo<ProductCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
