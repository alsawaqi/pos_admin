<?php

declare(strict_types=1);

namespace App\Support\Reversals\Models;

use Illuminate\Database\Eloquent\Model;

/** Scalar-cast adapter for the reserved payment reversal engine; no portal enum casts. */
final class QrSession extends Model
{
    protected $table = 'pos_qr_sessions';

    protected $guarded = [];

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ORDERED = 'ordered';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CLOSED = 'closed';

    public function isDineIn(): bool
    {
        return $this->table_id !== null;
    }

    protected function casts(): array
    {
        return ['closed_at' => 'datetime'];
    }
}
