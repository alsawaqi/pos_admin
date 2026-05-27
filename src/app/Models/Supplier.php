<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Read-only mirror of pos_merchant's Supplier. Schema owned
 * by pos_admin's 2026_05_29_010000; writes happen on the
 * merchant side via Actions.
 */
#[Fillable([])]
class Supplier extends Model
{
    use SoftDeletes;

    protected $table = 'pos_suppliers';

    protected $guarded = ['*'];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
