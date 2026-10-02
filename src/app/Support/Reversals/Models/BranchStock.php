<?php

declare(strict_types=1);

namespace App\Support\Reversals\Models;

use App\Casts\ScaledDecimal;
use Illuminate\Database\Eloquent\Model;

/** Scalar-cast adapter for the reserved payment reversal engine; no portal enum casts. */
final class BranchStock extends Model
{
    protected $table = 'pos_branch_stock';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => ScaledDecimal::class.':3,4'];
    }
}
