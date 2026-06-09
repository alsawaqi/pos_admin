<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * v2 #17 (Phase B) — a merchant payout: the platform's settlement of a date
 * range's merchant-commission earnings (pos_payouts). net_amount is the payable;
 * the platform/bank/other amounts are the snapshot deductions for the statement.
 * Lifecycle pending → paid | cancelled. Created + read by the admin only.
 */
class Payout extends Model
{
    protected $table = 'pos_payouts';

    protected $guarded = [];

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_from' => 'datetime',
            'period_to' => 'datetime',
            'paid_at' => 'datetime',
            'gross_amount' => 'decimal:3',
            'platform_amount' => 'decimal:3',
            'bank_amount' => 'decimal:3',
            'other_amount' => 'decimal:3',
            'net_amount' => 'decimal:3',
            'sales_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static function (self $row): void {
            if ($row->uuid === null || $row->uuid === '') {
                $row->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
