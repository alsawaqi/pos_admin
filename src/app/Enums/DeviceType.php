<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three physical device classes a merchant can be assigned, taken
 * verbatim from the blueprint §4.4.2 / §2.3.
 *
 *   FixedPos       — dual-screen Android terminal at the counter
 *                    (cashier-facing primary + customer-facing secondary).
 *                    Runs the Main POS app, the only surface that shows
 *                    the round-up prompt on its customer screen.
 *
 *   Handheld       — single-screen rugged Android PDA / smartphone used
 *                    by floor staff for tableside and drive-up orders.
 *                    Runs the Handheld POS app. Round-up is intentionally
 *                    NOT shown here because it is a customer-driven choice
 *                    and the handheld is staff-operated (blueprint §7.3).
 *
 *   CustomerTablet — self-service tablet for end customers to browse the
 *                    menu, pay, and opt into round-up. Runs the Customer
 *                    Tablet app. No staff login.
 *
 * The string values are persisted on the `pos_devices.device_type`
 * column. Changing a value here means writing a data migration that
 * rewrites existing rows — be careful.
 */
enum DeviceType: string
{
    case FixedPos = 'fixed_pos';
    case Handheld = 'handheld';
    case CustomerTablet = 'customer_tablet';

    /**
     * Flat list of values, mostly handy for `Rule::enum()` validators
     * and for seeders that need to iterate every case.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
