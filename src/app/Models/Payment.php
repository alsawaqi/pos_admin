<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only mirror of pos_merchant's Payment. Schema owned by
 * pos_admin's 2026_06_04_020000 migration.
 *
 * The Soft POS reconciliation queue on the admin side WRITES
 * to reconciled_by_admin_id + reconciled_at (the admin's own
 * Action), so even though most fields are read-only, those
 * two columns flip on admin matching. We keep $guarded = ['*']
 * here and let a dedicated admin-side Action use forceFill()
 * to update the two columns — explicit > permissive.
 *
 * Phase 7a — payment tender.
 */
#[Fillable([])]
class Payment extends Model
{
    protected $table = 'pos_payments';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount' => 'decimal:3',
            'change_given' => 'decimal:3',
            'status' => PaymentStatus::class,
            'pending_reconciliation' => 'boolean',
            'reconciled_at' => 'datetime',
            'captured_at' => 'datetime',
            // Bank + charity round-up fields (2026_06_17 migration).
            'bank_response' => 'array',
            'bank_id' => 'integer',
            'device_id' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'roundup_amount' => 'decimal:3',
            'charity_transaction_id' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by_admin_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePendingReconciliation(Builder $query): Builder
    {
        return $query->where('pending_reconciliation', true);
    }
}
