<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FloorStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * `pos_floors` row. Schema owned by this app
 * (2026_05_27_030000); pos_merchant does the actual CRUD
 * via Phase 5 actions. pos_admin exposes the model so a
 * future cross-merchant tool (audit / support) can query
 * floors without re-defining the shape.
 *
 * No fillable — direct ::create from inside pos_admin would
 * skip the audit trail pos_merchant ships.
 */
#[Fillable([])]
class Floor extends Model
{
    use SoftDeletes;

    protected $table = 'pos_floors';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FloorStatus::class,
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
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return HasMany<Table, $this>
     */
    public function tables(): HasMany
    {
        return $this->hasMany(Table::class);
    }
}
