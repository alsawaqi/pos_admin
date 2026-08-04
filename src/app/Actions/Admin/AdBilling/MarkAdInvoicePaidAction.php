<?php

declare(strict_types=1);

namespace App\Actions\Admin\AdBilling;

use App\Models\AdInvoice;
use RuntimeException;

/**
 * Phase 5 — record that the advertiser paid (invoice-first model: money
 * moves outside the system — bank transfer — and the operator records it).
 */
final class MarkAdInvoicePaidAction
{
    public function handle(AdInvoice $invoice, ?int $actorId): AdInvoice
    {
        // Re-read under lock: a concurrent void must never lose to (or
        // silently overwrite) a mark-paid — the status check and the write
        // must be one atomic step.
        return \Illuminate\Support\Facades\DB::transaction(function () use ($invoice, $actorId): AdInvoice {
            $locked = AdInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === AdInvoice::STATUS_VOID) {
                throw new RuntimeException('A voided invoice cannot be marked paid.');
            }
            if ($locked->status === AdInvoice::STATUS_PAID) {
                return $locked; // idempotent — double-click safe
            }

            $locked->forceFill([
                'status' => AdInvoice::STATUS_PAID,
                'paid_at' => now(),
                'paid_by_user_id' => $actorId,
            ])->save();

            return $locked->fresh();
        });
    }
}
