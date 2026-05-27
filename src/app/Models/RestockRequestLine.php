<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only mirror of pos_merchant's RestockRequestLine. Schema
 * owned by pos_admin's 2026_05_31_010200 migration.
 *
 * quantity_allocated starts at 0; the merchant-side allocation
 * action updates it when fulfilling the parent request.
 * Composite-unique on (restock_request_id, ingredient_id) keeps
 * a single line per ingredient per request.
 */
#[Fillable([])]
class RestockRequestLine extends Model
{
    protected $table = 'pos_restock_request_lines';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_requested' => 'decimal:3',
            'quantity_allocated' => 'decimal:3',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<RestockRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(RestockRequest::class, 'restock_request_id');
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
