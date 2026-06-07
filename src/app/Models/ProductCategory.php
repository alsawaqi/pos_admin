<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CategoryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * `pos_product_categories` row. Schema owned by this app's
 * 2026_05_27_040000 migration; pos_merchant handles the CRUD
 * via Phase 6 actions. Read-only mirror here for cross-
 * merchant reporting + audit drill-downs.
 */
#[Fillable([])]
class ProductCategory extends Model
{
    use SoftDeletes;

    protected $table = 'pos_product_categories';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CategoryStatus::class,
            'display_order' => 'integer',
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
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }
}
