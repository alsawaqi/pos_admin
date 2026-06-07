<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AddOnSelectionMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Read-only mirror of pos_merchant's AddOnGroup. Schema owned
 * by pos_admin's 2026_05_28_010000 migration, but the data is
 * written exclusively by the merchant portal via Actions.
 *
 * $guarded = ['*'] so any accidental mass-assign here can't
 * bypass the merchant-side fillable whitelist.
 */
#[Fillable([])]
class AddOnGroup extends Model
{
    use SoftDeletes;

    protected $table = 'pos_addon_groups';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'selection_mode' => AddOnSelectionMode::class,
            'is_global' => 'boolean',
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
     * @return HasMany<AddOn, $this>
     */
    public function addOns(): HasMany
    {
        return $this->hasMany(AddOn::class);
    }
}
