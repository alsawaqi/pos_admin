<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Read-only mirror of pos_merchant's LoyaltyAccount. Schema owned
 * by pos_admin's 2026_06_08_010100 migration.
 *
 * Loyalty refactor (blueprint §10.6).
 */
#[Fillable([])]
class LoyaltyAccount extends Model
{
    protected $table = 'pos_loyalty_accounts';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stamp_count' => 'integer',
            'point_balance' => 'integer',
            'last_activity_at' => 'datetime',
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
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<LoyaltyRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(LoyaltyRule::class, 'loyalty_rule_id');
    }

    /**
     * @return HasMany<LoyaltyTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }
}
