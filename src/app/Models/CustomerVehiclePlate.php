<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only mirror of pos_merchant's CustomerVehiclePlate.
 * Schema owned by pos_admin's 2026_06_01_010100 migration;
 * writes happen on the merchant side.
 *
 * Phase 6a — 1:N vehicle plates attached to a customer.
 */
#[Fillable([])]
class CustomerVehiclePlate extends Model
{
    protected $table = 'pos_customer_vehicle_plates';

    protected $guarded = ['*'];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
