<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Phase B — a commission INVOICE: the merchant's settlement TO the platform for
 * a period's cash/bank_pos commission (pos_commission_invoices). total_owed is
 * what the merchant remits; platform/other/merchant amounts are the snapshot
 * breakdown for the statement. Lifecycle issued → paid | void. The reverse
 * direction of {@see Payout}. Created + issued by the admin; read by the admin
 * and (read-only) by the merchant portal over the shared DB.
 */
class CommissionInvoice extends Model
{
    protected $table = 'pos_commission_invoices';

    protected $guarded = [];

    public const STATUS_ISSUED = 'issued';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'period_from' => 'datetime',
            'period_to' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
            'gross_amount' => 'decimal:3',
            'platform_amount' => 'decimal:3',
            'other_amount' => 'decimal:3',
            'merchant_amount' => 'decimal:3',
            'total_owed' => 'decimal:3',
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
