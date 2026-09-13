<?php

declare(strict_types=1);

namespace App\Support\Reversals\Models;

use Illuminate\Database\Eloquent\Model;

/** Scalar-cast adapter for the reserved payment reversal engine; no portal enum casts. */
final class QrOrderRound extends Model
{
    protected $table = 'pos_qr_order_rounds';

    protected $guarded = [];

    public const STATUS_PENDING_CONFIRMATION = 'pending_confirmation';

    public const STATUS_REJECTED = 'rejected';

    protected function casts(): array
    {
        return ['confirm_payload' => 'array'];
    }
}
