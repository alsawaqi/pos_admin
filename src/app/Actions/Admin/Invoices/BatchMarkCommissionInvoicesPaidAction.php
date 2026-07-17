<?php

declare(strict_types=1);

namespace App\Actions\Admin\Invoices;

use App\Models\CommissionInvoice;
use Illuminate\Support\Facades\DB;

/**
 * Phase B — mark several ISSUED invoices paid in one go (the admin clears a batch
 * after a collection run). Non-issued invoices in the set are SKIPPED (not
 * errored); the result reports how many were marked vs skipped. Mirror of
 * {@see \App\Actions\Admin\Payouts\BatchMarkPayoutsPaidAction}.
 */
final class BatchMarkCommissionInvoicesPaidAction
{
    public function __construct(
        private readonly MarkCommissionInvoicePaidAction $markPaid,
    ) {}

    /**
     * @param  list<string>  $uuids
     * @return array{marked: int, skipped: int}
     */
    public function handle(array $uuids, ?int $actorId, ?string $reference = null, ?string $note = null): array
    {
        if ($uuids === []) {
            return ['marked' => 0, 'skipped' => 0];
        }

        return DB::transaction(function () use ($uuids, $actorId, $reference, $note): array {
            $invoices = CommissionInvoice::query()
                ->whereIn('uuid', array_values(array_unique($uuids)))
                ->lockForUpdate()
                ->get();

            $marked = 0;
            foreach ($invoices as $invoice) {
                if ($invoice->status !== CommissionInvoice::STATUS_ISSUED) {
                    continue; // already paid/void — leave it untouched
                }
                $this->markPaid->handle($invoice, $actorId, $reference, $note);
                $marked++;
            }

            return ['marked' => $marked, 'skipped' => $invoices->count() - $marked];
        });
    }
}
