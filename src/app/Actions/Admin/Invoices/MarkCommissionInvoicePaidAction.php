<?php

declare(strict_types=1);

namespace App\Actions\Admin\Invoices;

use App\Models\CommissionInvoice;
use RuntimeException;

/**
 * Phase B — mark an issued invoice PAID (the merchant remitted the commission).
 * Captures the settlement reference + who/when. A paid invoice is terminal.
 * Mirror of {@see \App\Actions\Admin\Payouts\MarkPayoutPaidAction}.
 */
final class MarkCommissionInvoicePaidAction
{
    public function handle(CommissionInvoice $invoice, ?int $actorId, ?string $reference = null, ?string $note = null): CommissionInvoice
    {
        if ($invoice->status !== CommissionInvoice::STATUS_ISSUED) {
            throw new RuntimeException('Only an issued invoice can be marked paid (current status: '.$invoice->status.').');
        }

        $invoice->update([
            'status' => CommissionInvoice::STATUS_PAID,
            'paid_by_user_id' => $actorId,
            'paid_at' => now(),
            'reference' => $reference,
            'note' => $note,
        ]);

        return $invoice->fresh();
    }
}
