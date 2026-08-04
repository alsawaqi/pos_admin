<?php

declare(strict_types=1);

namespace App\Actions\Admin\AdBilling;

use App\Models\AdInvoice;
use RuntimeException;

/**
 * Phase 5 — void an issued invoice (wrong period, disputed delivery, …).
 * The period becomes re-issuable; the voided document stays on record
 * with who/when/why. A PAID invoice cannot be voided (the money already
 * moved — that correction is a human conversation, not a status flip).
 */
final class VoidAdInvoiceAction
{
    public function handle(AdInvoice $invoice, ?int $actorId, ?string $reason = null): AdInvoice
    {
        // Re-read under lock (mirror of MarkAdInvoicePaidAction): a paid
        // invoice must never be voided by a racing request that read the
        // pre-payment status.
        return \Illuminate\Support\Facades\DB::transaction(function () use ($invoice, $actorId, $reason): AdInvoice {
            $locked = AdInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === AdInvoice::STATUS_PAID) {
                throw new RuntimeException('A paid invoice cannot be voided.');
            }
            if ($locked->status === AdInvoice::STATUS_VOID) {
                return $locked; // idempotent
            }

            $locked->forceFill([
                'status' => AdInvoice::STATUS_VOID,
                'voided_at' => now(),
                'voided_by_user_id' => $actorId,
                'note' => trim(($locked->note !== null ? $locked->note.' | ' : '').'VOID: '.($reason ?? 'no reason given')),
            ])->save();

            return $locked->fresh();
        });
    }
}
