<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WalletLedgerEntryType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only mirror of pos_merchant's CustomerWalletLedgerEntry.
 * Schema owned by pos_admin's 2026_06_02_010300 migration.
 *
 * Phase 6b — append-only customer wallet ledger.
 */
#[Fillable([])]
class CustomerWalletLedgerEntry extends Model
{
    protected $table = 'pos_customer_wallet_ledger';

    protected $guarded = ['*'];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_type' => WalletLedgerEntryType::class,
            'amount_delta' => 'decimal:3',
            'balance_after' => 'decimal:3',
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
