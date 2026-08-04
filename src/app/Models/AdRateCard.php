<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 5 advertiser billing — what an advertiser pays for delivery.
 * One ACTIVE card per advertiser (SetAdRateCardAction deactivates the
 * previous one; history rows stay for pricing audit). Written only by
 * the pos_admin billing actions.
 */
class AdRateCard extends Model
{
    public const MODEL_PER_DAY_PER_SCREEN = 'per_day_per_screen';

    public const MODEL_PER_THOUSAND_IMPRESSIONS = 'per_thousand_impressions';

    public const MODELS = [
        self::MODEL_PER_DAY_PER_SCREEN,
        self::MODEL_PER_THOUSAND_IMPRESSIONS,
    ];

    protected $table = 'pos_ad_rate_cards';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'advertiser_id' => 'integer',
            'rate' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Advertiser, $this>
     */
    public function advertiser(): BelongsTo
    {
        return $this->belongsTo(Advertiser::class);
    }
}
