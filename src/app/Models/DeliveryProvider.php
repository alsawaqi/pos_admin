<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Read-only mirror of pos_merchant's DeliveryProvider. Schema
 * owned by pos_admin's 2026_06_03_010000 migration; writes
 * happen on the merchant side.
 *
 * Phase 6c — per-merchant 3rd-party delivery aggregator.
 */
#[Fillable([])]
class DeliveryProvider extends Model
{
    use SoftDeletes;

    protected $table = 'pos_delivery_providers';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            // P-G7 — the provider's cut of a delivery order.
            'commission_percent' => 'decimal:2',
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
     * @return HasMany<ProductDeliveryPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ProductDeliveryPrice::class, 'delivery_provider_id');
    }
}
