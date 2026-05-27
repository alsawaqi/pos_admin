<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IngredientUnit;
use App\Enums\WasteReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only mirror of pos_merchant's WasteRecord. Schema owned
 * by pos_admin's 2026_05_31_010000 migration; writes happen on
 * the merchant side via RecordWasteAction (Phase 5c).
 *
 * The matching stock_movement (type=waste, signed-negative
 * quantity) points back here via reference_type=WasteRecord +
 * reference_id=this.id.
 */
#[Fillable([])]
class WasteRecord extends Model
{
    protected $table = 'pos_waste_records';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'reason' => WasteReason::class,
            'unit_at_set' => IngredientUnit::class,
            'unit_cost_at_time' => 'decimal:3',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
