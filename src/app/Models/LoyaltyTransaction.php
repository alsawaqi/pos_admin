<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LoyaltyTransactionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only mirror of pos_merchant's LoyaltyTransaction. Schema
 * owned by pos_admin's 2026_06_08_010200 migration.
 *
 * Loyalty refactor (blueprint §10.6).
 */
#[Fillable([])]
class LoyaltyTransaction extends Model
{
    protected $table = 'pos_loyalty_transactions';

    public $timestamps = false;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LoyaltyTransactionType::class,
            'points_delta' => 'integer',
            'stamps_delta' => 'integer',
            'balance_after_points' => 'integer',
            'balance_after_stamps' => 'integer',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LoyaltyAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LoyaltyAccount::class, 'loyalty_account_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
