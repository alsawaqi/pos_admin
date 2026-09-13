<?php

declare(strict_types=1);

namespace App\Support\Reversals\Models;

use Illuminate\Database\Eloquent\Model;

/** Scalar-cast adapter for the reserved payment reversal engine; no portal enum casts. */
final class RoundupDonation extends Model
{
    protected $table = 'pos_roundup_donations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:3'];
    }
}
