<?php

declare(strict_types=1);

namespace App\Enums;

enum BusinessActivityCategory: string
{
    case FoodAndBeverage = 'food_and_beverage';
    case Retail = 'retail';
    case Services = 'services';
    case Hospitality = 'hospitality';
    case Healthcare = 'healthcare';
    case Education = 'education';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
