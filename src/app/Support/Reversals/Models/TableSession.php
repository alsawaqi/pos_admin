<?php

declare(strict_types=1);

namespace App\Support\Reversals\Models;

use Illuminate\Database\Eloquent\Model;

/** Scalar-cast adapter for the reserved payment reversal engine; no portal enum casts. */
final class TableSession extends Model
{
    protected $table = 'pos_table_sessions';

    protected $guarded = [];

    public const STATUS_OPEN = 'open';

    public const STATUS_BILLING = 'billing';

    public const STATUS_CLOSED = 'closed';

    public const CLOSE_PAID = 'paid';

    public const CLOSE_VOIDED = 'voided';

    protected function casts(): array
    {
        return ['closed_at' => 'datetime'];
    }
}
