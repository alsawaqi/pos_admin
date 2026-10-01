<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * LAUNCH-P1 B1 — one activation attempt the serial/app lock refused (enforce)
 * or let through with a recorded mismatch (report). Written by pos_api; the
 * admin only reads it (device page). The reported serial is masked + hashed.
 */
class DeviceActivationAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'pos_device_activation_attempts';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
