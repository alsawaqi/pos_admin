<?php

declare(strict_types=1);

namespace App\Actions\Admin\Invoices;

use App\Models\CommissionInvoice;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase B — void an ISSUED invoice: release its claimed commission rows
 * (invoice_id → NULL) so they can be billed on a future invoice, and mark it
 * void. A paid invoice is terminal and cannot be voided. Mirror of
 * {@see \App\Actions\Admin\Payouts\CancelPayoutAction}.
 */
final class VoidCommissionInvoiceAction
{
    public function handle(CommissionInvoice $invoice, ?int $actorId = null): CommissionInvoice
    {
        if ($invoice->status !== CommissionInvoice::STATUS_ISSUED) {
            throw new RuntimeException('Only an issued invoice can be voided (current status: '.$invoice->status.').');
        }

        return DB::transaction(function () use ($invoice, $actorId): CommissionInvoice {
            DB::table('pos_sale_commissions')
                ->where('invoice_id', $invoice->id)
                ->update(['invoice_id' => null]);

            $invoice->update([
                'status' => CommissionInvoice::STATUS_VOID,
                'voided_by_user_id' => $actorId,
                'voided_at' => now(),
            ]);

            return $invoice->fresh();
        });
    }
}
