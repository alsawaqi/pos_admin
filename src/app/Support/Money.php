<?php

declare(strict_types=1);

namespace App\Support;

/**
 * TWIN of pos_api app/Support/Money.php — keep in sync.
 *
 * The baisas⇄OMR boundary converter (the 8.1 money contract). Money on the
 * device wire is INTEGER BAISAS (1 OMR = 1000 baisas) so nothing ever does
 * float math; the shared pos_* schema stores OMR as decimal(12,3).
 *
 * P-F7 — pos_admin needs it because the reconciliation approval queue
 * replays pos_api's commission split (RecordSaleCommissionAction twin) for
 * sales whose money effects were deferred on a pending tender.
 */
final class Money
{
    /** Integer baisas → decimal(12,3) OMR string, e.g. 2835 → "2.835". */
    public static function toOmr(int $baisas): string
    {
        return number_format($baisas / 1000, 3, '.', '');
    }

    /** decimal OMR (string|float) → integer baisas, e.g. "2.835" → 2835. */
    public static function toBaisas(int|float|string $omr): int
    {
        return (int) round(((float) $omr) * 1000);
    }
}
