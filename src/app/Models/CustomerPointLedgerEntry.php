<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PointLedgerEntryType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only mirror of pos_merchant's CustomerPointLedgerEntry.
 * Schema owned by pos_admin's 2026_06_02_010200 migration.
 *
 * Phase 6b — append-only customer points ledger.
 */
#[Fillable([])]
class CustomerPointLedgerEntry extends Model
{
    protected $table = 'pos_customer_point_ledger';

    protected $guarded = ['*'];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_type' => PointLedgerEntryType::class,
            'points_delta' => 'integer',
            'balance_after' => 'integer',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

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
