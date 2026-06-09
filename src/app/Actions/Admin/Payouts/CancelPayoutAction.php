<?php

declare(strict_types=1);

namespace App\Actions\Admin\Payouts;

use App\Models\Payout;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v2 #17 (Phase B) — cancel a PENDING payout: release its claimed commission
 * rows (payout_id → NULL) so they're available to a future payout, and mark it
 * cancelled. A paid payout is terminal and cannot be cancelled.
 */
final class CancelPayoutAction
{
    public function handle(Payout $payout): Payout
    {
        if ($payout->status !== Payout::STATUS_PENDING) {
            throw new RuntimeException('Only a pending payout can be cancelled (current status: '.$payout->status.').');
        }

        return DB::transaction(function () use ($payout): Payout {
            DB::table('pos_sale_commissions')
                ->where('payout_id', $payout->id)
                ->update(['payout_id' => null]);

            $payout->update(['status' => Payout::STATUS_CANCELLED]);

            return $payout->fresh();
        });
    }
}
