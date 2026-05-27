<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle states for a pos_tables row.
 *
 * Operational table states (occupied / dirty / reserved /
 * available) are NOT modeled as a column — those derive from
 * live order state at the POS device's read time. This enum
 * is the persistent admin-managed state: is the table on the
 * roster at all?
 */
enum TableStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
