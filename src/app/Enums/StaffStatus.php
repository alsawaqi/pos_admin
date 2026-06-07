<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle states for a POS staff row.
 *
 *   active     — currently employed, PIN unlocks the device.
 *   suspended  — temporary block (investigation, pay dispute,
 *                training hold). Pin lookups must refuse during
 *                this state but the row remains untouched, ready
 *                to flip back to active.
 *   terminated — employment ended. SoftDeletes::deleted_at is
 *                stamped at the same moment by the Action layer,
 *                so listing queries scope to ->whereNull('deleted_at')
 *                and only audit / order-history joins see the row.
 *                Re-hiring is supported: clear deleted_at + status
 *                + reset PIN. Re-issuing the same staff_code is
 *                allowed because the partial-unique index also
 *                filters on deleted_at IS NULL.
 */
enum StaffStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Terminated = 'terminated';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
