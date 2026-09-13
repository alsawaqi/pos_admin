<?php

declare(strict_types=1);

namespace App\Support\Reversals\Models;

use Illuminate\Database\Eloquent\Model;

/** Scalar-cast adapter for the reserved payment reversal engine; no portal enum casts. */
final class TableSessionEvent extends Model
{
    protected $table = 'pos_table_session_events';

    protected $guarded = [];

    public $timestamps = false;

    public const EVENT_TYPES = ['round_resolved', 'closed'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
