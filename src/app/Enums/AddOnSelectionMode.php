<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 4.9 — single-vs-multi selection within an add-on group.
 * Mirror of pos_merchant's enum. Both apps reference their own
 * namespace so neither imports the other.
 */
enum AddOnSelectionMode: string
{
    case Single = 'single';
    case Multi = 'multi';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
