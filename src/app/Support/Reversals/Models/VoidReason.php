<?php

declare(strict_types=1);

namespace App\Support\Reversals\Models;

use Illuminate\Database\Eloquent\Model;

/** Scalar-cast adapter for the reserved payment reversal engine; no portal enum casts. */
final class VoidReason extends Model
{
    protected $table = 'pos_void_reasons';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['affects_inventory' => 'boolean', 'is_active' => 'boolean'];
    }
}
