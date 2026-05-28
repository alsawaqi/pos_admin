<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only mirror of pos_merchant's ProductDeliveryPrice.
 * Schema owned by pos_admin's 2026_06_03_010100 migration;
 * writes happen on the merchant side.
 *
 * Phase 6c — per-provider price override for a product.
 */
#[Fillable([])]
class ProductDeliveryPrice extends Model
{
    protected $table = 'pos_product_delivery_prices';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<DeliveryProvider, $this>
     */
    public function deliveryProvider(): BelongsTo
    {
        return $this->belongsTo(DeliveryProvider::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
