<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only mirror of pos_merchant's CustomerLoyaltyConfig.
 * Schema owned by pos_admin's 2026_06_02_010000 migration;
 * writes happen on the merchant side.
 *
 * Phase 6b — per-company loyalty config singleton.
 */
#[Fillable([])]
class CustomerLoyaltyConfig extends Model
{
    protected $table = 'pos_customer_loyalty_configs';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'points_per_omr' => 'integer',
            'baisas_per_point' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
