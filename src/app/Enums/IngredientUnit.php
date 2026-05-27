<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Phase 5a — units of measure for ingredients.
 * Mirror of pos_merchant's enum.
 */
enum IngredientUnit: string
{
    case Kilogram = 'kg';
    case Gram = 'g';
    case Litre = 'l';
    case Millilitre = 'ml';
    case Piece = 'piece';
    case Pack = 'pack';
    case Box = 'box';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
