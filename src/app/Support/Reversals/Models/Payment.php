<?php

declare(strict_types=1);

namespace App\Support\Reversals\Models;

use Illuminate\Database\Eloquent\Model;

/** Scalar-cast adapter for the reserved payment reversal engine; no portal enum casts. */
final class Payment extends Model
{
    protected $table = 'pos_payments';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['roundup_amount' => 'decimal:3'];
    }
}
