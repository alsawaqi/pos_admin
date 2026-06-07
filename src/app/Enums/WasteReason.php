<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 5c — waste reason taxonomy.
 * Mirror of pos_merchant's enum.
 */
enum WasteReason: string
{
    case Expired = 'expired';
    case Spoiled = 'spoiled';
    case Broken = 'broken';
    case Dropped = 'dropped';
    case Contamination = 'contamination';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
