<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\ScaledDecimal;
use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only mirror of pos_merchant's StockMovement. Schema owned
 * by pos_admin's 2026_05_29_010300. Append-only ledger — writes
 * happen on the merchant side inside WriteStockMovementAction.
 */
#[Fillable([])]
class StockMovement extends Model
{
    protected $table = 'pos_stock_movements';

    protected $guarded = ['*'];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'movement_type' => StockMovementType::class,
            'quantity' => ScaledDecimal::class.':3,4',
            'unit_cost_at_time' => ScaledDecimal::class.':3,6',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
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
