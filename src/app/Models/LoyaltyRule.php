<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LoyaltyRuleStatus;
use App\Enums\LoyaltyRuleType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Read-only mirror of pos_merchant's LoyaltyRule. Schema owned by
 * pos_admin's 2026_06_08_010000 migration; writes happen on the
 * merchant side.
 *
 * Loyalty refactor (blueprint §5.8 + §10.6).
 */
#[Fillable([])]
class LoyaltyRule extends Model
{
    use SoftDeletes;

    protected $table = 'pos_loyalty_rules';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LoyaltyRuleType::class,
            'config_json' => 'array',
            'validity_start' => 'datetime',
            'validity_end' => 'datetime',
            'status' => LoyaltyRuleStatus::class,
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
     * @return HasMany<LoyaltyAccount, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(LoyaltyAccount::class);
    }
}
