<?php

declare(strict_types=1);

namespace App\Support\Reversals\Models;

use Illuminate\Database\Eloquent\Model;

/** Scalar-cast adapter for the reserved payment reversal engine; no portal enum casts. */
final class BranchProduct extends Model
{
    protected $table = 'pos_branch_product';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['stock_qty' => 'decimal:3'];
    }
}
