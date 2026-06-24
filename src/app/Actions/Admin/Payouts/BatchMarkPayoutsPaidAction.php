<?php

declare(strict_types=1);

namespace App\Actions\Admin\Payouts;

use App\Models\Payout;
use Illuminate\Support\Facades\DB;

/**
 * Mark several PENDING payouts paid in one go — the admin clears a batch after a
 * bank run instead of one row at a time. Non-pending payouts in the set are
 * SKIPPED (not errored), so a mixed selection still lands; the result reports
 * how many were marked vs skipped. Each row goes through the single
 * MarkPayoutPaidAction, all inside one row-locked transaction.
 */
final class BatchMarkPayoutsPaidAction
{
    public function __construct(
        private readonly MarkPayoutPaidAction $markPaid,
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
            // Lock the selected payouts so a concurrent mark-paid can't double-run.
            $payouts = Payout::query()
                ->whereIn('uuid', array_values(array_unique($uuids)))
                ->lockForUpdate()
                ->get();

            $marked = 0;
            foreach ($payouts as $payout) {
                if ($payout->status !== Payout::STATUS_PENDING) {
                    continue; // already paid/cancelled — leave it untouched
                }
                $this->markPaid->handle($payout, $actorId, $reference, $note);
                $marked++;
            }

            return ['marked' => $marked, 'skipped' => $payouts->count() - $marked];
        });
    }
}
