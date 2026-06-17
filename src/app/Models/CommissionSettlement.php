<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A commission SETTLEMENT event (pos_commission_settlements) — one reconciliation
 * of a merchant's card sales against the bank's ACTUAL fee. The per-order detail
 * lives on pos_sale_commissions (settled_amount + settlement_id); this row is the
 * audit header: estimated vs actual bank, the variance the merchant's net moved,
 * and reversal evidence. Created + read by the admin only.
 */
class CommissionSettlement extends Model
{
    protected $table = 'pos_commission_settlements';

    protected $guarded = [];

    public const STATUS_APPLIED = 'applied';

    public const STATUS_REVERSED = 'reversed';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_BANK_FILE = 'bank_file';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'period_from' => 'datetime',
            'period_to' => 'datetime',
            'reversed_at' => 'datetime',
            'card_gross' => 'decimal:3',
            'estimated_bank' => 'decimal:3',
            'actual_bank' => 'decimal:3',
            'platform_total' => 'decimal:3',
            'merchant_net' => 'decimal:3',
            'variance' => 'decimal:3',
            'orders_count' => 'integer',
            'bank_id' => 'integer',
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
