<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Job classifications for PIN-authenticated POS staff.
 *
 * Kept deliberately small for Phase 4.6 — these five cover the
 * F&B operating model that MITHQAL ships against today (cafés,
 * restaurants, ghost kitchens). Retail-leaning verticals (where
 * "stock clerk" or "barista" matter) can either:
 *   - get covered by a future expansion to this enum, or
 *   - flip the column to free-form text behind a settings flag.
 *
 * Whatever path we pick later, the migration column is a plain
 * string(32) — no hard-coded enum at the DB layer — so changing
 * the application enum doesn't require a column rewrite.
 */
enum StaffPosition: string
{
    case Cashier = 'cashier';
    case Waiter = 'waiter';
    case Kitchen = 'kitchen';
    case Manager = 'manager';
    case Supervisor = 'supervisor';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
