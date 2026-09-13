<?php

declare(strict_types=1);

namespace App\Support\Reversals\Models;

use Illuminate\Database\Eloquent\Model;

/** Scalar-cast adapter for the reserved payment reversal engine; no portal enum casts. */
final class LoyaltyAccount extends Model
{
    protected $table = 'pos_loyalty_accounts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['point_balance' => 'integer', 'stamp_count' => 'integer'];
    }
}
