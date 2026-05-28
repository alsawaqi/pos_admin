<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Read-only mirror of pos_merchant's Customer. Schema owned
 * by pos_admin's 2026_06_01_010000 migration; writes happen
 * on the merchant side.
 *
 * Phase 6a — customer master at company level.
 */
#[Fillable([])]
class Customer extends Model
{
    use SoftDeletes;

    protected $table = 'pos_customers';

    protected $guarded = ['*'];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<CustomerVehiclePlate, $this>
     */
    public function vehiclePlates(): HasMany
    {
        return $this->hasMany(CustomerVehiclePlate::class);
    }
}
