<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TWIN of pos_api app/Models/SaleCommission.php — keep in sync.
 *
 * Append-only per-sale commission breakdown (pos_sale_commissions, schema
 * owned by pos_admin's 2026_06_26_010200 migration) — one row per party
 * (platform / bank / other / merchant). Normally written by pos_api at
 * order.pay; P-F7 adds the deferred path where pos_admin's reconciliation
 * approval writes the identical rows once a pending tender is confirmed
 * (see App\Actions\Admin\Reconciliation\RecordSaleCommissionAction).
 *
 * Unguarded like its pos_api twin: the ONLY writer on this side is the
 * reconciliation action, which builds every attribute explicitly.
 */
class SaleCommission extends Model
{
    protected $table = 'pos_sale_commissions';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'percent' => 'decimal:2',
            'gross_amount' => 'decimal:3',
            'commission_amount' => 'decimal:3',
            'occurred_at' => 'datetime',
        ];
    }
}
