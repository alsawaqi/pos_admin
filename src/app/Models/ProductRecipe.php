<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IngredientUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only mirror of pos_merchant's ProductRecipe. Schema
 * owned by pos_admin's 2026_05_30_010000 migration; writes
 * happen on the merchant side via UpdateProductRecipeAction.
 */
#[Fillable([])]
class ProductRecipe extends Model
{
    protected $table = 'pos_product_recipes';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_at_set' => IngredientUnit::class,
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
