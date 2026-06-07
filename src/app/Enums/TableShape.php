<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Physical shape of a pos_tables row. Cosmetic in Phase 5
 * (the flat-list planner doesn't render shapes); Phase 5.5
 * visual floor planner will draw the correct outline.
 *
 * Free-form on the DB side (string column, no CHECK
 * constraint) so adding "booth" or "high_top" later doesn't
 * require a migration. This enum is the application-side
 * validation whitelist.
 */
enum TableShape: string
{
    case Round = 'round';
    case Square = 'square';
    case Rectangle = 'rectangle';
    case Oval = 'oval';
    case Counter = 'counter';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
