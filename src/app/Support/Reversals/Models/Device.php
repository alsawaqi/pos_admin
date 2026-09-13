<?php

declare(strict_types=1);

namespace App\Support\Reversals\Models;

use Illuminate\Database\Eloquent\Model;

/** Scalar-cast adapter for the reserved payment reversal engine; no portal enum casts. */
final class Device extends Model
{
    protected $table = 'pos_devices';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'branch_id' => 'integer'];
    }
}
