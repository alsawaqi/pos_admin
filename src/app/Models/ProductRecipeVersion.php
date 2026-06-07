<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only mirror of pos_merchant's ProductRecipeVersion.
 * Schema owned by pos_admin's 2026_05_30_010100 migration.
 */
#[Fillable([])]
class ProductRecipeVersion extends Model
{
    protected $table = 'pos_product_recipe_versions';

    protected $guarded = ['*'];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipe_json' => 'array',
            'edited_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
