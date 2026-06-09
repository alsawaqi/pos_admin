<?php

declare(strict_types=1);

namespace App\Actions\Admin\Payouts;

use App\Models\Payout;
use RuntimeException;

/**
 * v2 #17 (Phase B) — mark a pending payout PAID (records the disbursement).
 * Captures the settlement reference + who/when. A paid payout is terminal.
 */
final class MarkPayoutPaidAction
{
    public function handle(Payout $payout, ?int $actorId, ?string $reference = null, ?string $note = null): Payout
    {
        if ($payout->status !== Payout::STATUS_PENDING) {
            throw new RuntimeException('Only a pending payout can be marked paid (current status: '.$payout->status.').');
        }

        $payout->update([
            'status' => Payout::STATUS_PAID,
            'paid_by_user_id' => $actorId,
            'paid_at' => now(),
            'reference' => $reference,
            'note' => $note,
        ]);

        return $payout->fresh();
    }
}
