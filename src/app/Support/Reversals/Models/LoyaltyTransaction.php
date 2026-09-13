<?php

declare(strict_types=1);

namespace App\Support\Reversals\Models;

use Illuminate\Database\Eloquent\Model;

/** Scalar-cast adapter for the reserved payment reversal engine; no portal enum casts. */
final class LoyaltyTransaction extends Model
{
    protected $table = 'pos_loyalty_transactions';

    protected $guarded = [];

    public $timestamps = false;

    public const TYPE_EARN = 'earn';

    public const TYPE_REDEEM = 'redeem';

    public const TYPE_ADJUST = 'adjust';

    protected function casts(): array
    {
        return ['points_delta' => 'integer', 'stamps_delta' => 'integer'];
    }
}
