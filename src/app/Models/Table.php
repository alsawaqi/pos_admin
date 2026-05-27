<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TableShape;
use App\Enums\TableStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * `pos_tables` row. Read-only on the pos_admin side; CRUD
 * lives in pos_merchant Phase 5 actions.
 *
 * Note: the table NAME is `pos_tables` (plural-with-prefix),
 * not `tables`. SQL keyword sensitivity varies by driver but
 * Laravel quotes the identifier.
 */
#[Fillable([])]
class Table extends Model
{
    use SoftDeletes;

    protected $table = 'pos_tables';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seats' => 'integer',
            'min_party' => 'integer',
            'max_party' => 'integer',
            'display_order' => 'integer',
            'status' => TableStatus::class,
            'shape' => TableShape::class,
        ];
    }

    /**
     * @return BelongsTo<Floor, $this>
     */
    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
