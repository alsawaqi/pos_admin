<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * POS charity round-up donation. WRITTEN by pos_api's round-up writer at
 * payment time; the admin only READS it (reporting / reconciliation), so
 * the model is guarded read-only like {@see Payment}. Schema owned by
 * pos_admin's 2026_06_18 migration.
 */
#[Fillable([])]
class RoundupDonation extends Model
{
    protected $table = 'pos_roundup_donations';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'branch_id' => 'integer',
            'device_id' => 'integer',
            'order_id' => 'integer',
            'payment_id' => 'integer',
            'bank_id' => 'integer',
            'commission_profile_id' => 'integer',
            'amount' => 'decimal:3',
            'bank_response' => 'array',
            'country_id' => 'integer',
            'region_id' => 'integer',
            'district_id' => 'integer',
            'city_id' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'occurred_at' => 'datetime',
            // P-F7 — set once the round-up has actually been forwarded to
            // the charity app; NULL = not yet forwarded (deferred while the
            // paying tender awaits reconciliation, or the forward failed).
            // The reconciliation approval forwards NULL-marker rows and
            // stamps this via forceFill (model stays guarded otherwise).
            'forwarded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
