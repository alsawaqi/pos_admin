<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IngredientUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Read-only mirror of pos_merchant's Ingredient. Schema owned
 * by pos_admin's 2026_05_29_010100 migration; writes happen
 * on the merchant side.
 */
#[Fillable([])]
class Ingredient extends Model
{
    use SoftDeletes;

    protected $table = 'pos_ingredients';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit' => IngredientUnit::class,
            'default_unit_cost' => 'decimal:3',
            'min_stock_threshold' => 'decimal:3',
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
     * @return BelongsTo<Supplier, $this>
     */
    public function primarySupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'primary_supplier_id');
    }
}
