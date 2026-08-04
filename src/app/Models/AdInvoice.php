<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 5 advertiser billing — an issued period bill, everything frozen
 * at issue time (pricing snapshot + metered delivery quantities), so the
 * document never changes under the advertiser. Lifecycle: issued → paid
 * | void. marketing-api reads this shared table (read-only) for the
 * advertiser portal's "My invoices".
 */
class AdInvoice extends Model
{
    public const STATUS_ISSUED = 'issued';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    protected $table = 'pos_ad_invoices';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'advertiser_id' => 'integer',
            'period_from' => 'date',
            'period_to' => 'date',
            'rate' => 'decimal:3',
            'amount' => 'decimal:3',
            'impressions_count' => 'integer',
            'play_seconds' => 'integer',
            'screen_days' => 'integer',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
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
