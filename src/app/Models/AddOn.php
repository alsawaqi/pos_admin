<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Read-only mirror of pos_merchant's AddOn. Schema owned by
 * pos_admin's 2026_05_28_010000 migration; writes happen only
 * on the merchant side.
 */
#[Fillable([])]
class AddOn extends Model
{
    use SoftDeletes;

    protected $table = 'pos_addons';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_delta' => 'decimal:3',
            'ingredient_qty' => 'decimal:3',
            'display_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AddOnGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(AddOnGroup::class, 'add_on_group_id');
    }
}
