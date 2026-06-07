<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle states for a pos_floors row.
 *
 *   active   — visible in the POS device's floor picker;
 *              tables on it can be opened against new orders.
 *   inactive — hidden from the picker (no new orders), but
 *              the row stays put + the floor's tables remain
 *              visible in the merchant portal for editing.
 *              Reversible via flipping back to active.
 */
enum FloorStatus: string
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
