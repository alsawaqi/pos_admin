<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle states for a pos_product_categories row.
 *
 *   active   — visible in the POS device's category picker;
 *              products under it are orderable.
 *   inactive — hidden from the picker (seasonal menu sections,
 *              discontinued ranges) without losing the row.
 *              Products under an inactive category retain
 *              their own status — the controller layer
 *              chooses whether to cascade the hide on the
 *              POS-facing payload.
 */
enum CategoryStatus: string
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
