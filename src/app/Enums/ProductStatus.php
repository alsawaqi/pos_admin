<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle states for a pos_products row.
 *
 *   active   — orderable + counts toward inventory + appears
 *              in the POS device picker.
 *   inactive — temporarily not for sale (out of stock, recipe
 *              under review). Stays visible in the merchant
 *              portal for editing; hidden from the POS-facing
 *              payload.
 *
 * Soft delete is separate — `deleted_at` is the "this product
 * no longer exists" signal; status is the "this product exists
 * but isn't selling right now" signal. The merchant portal
 * surfaces both states; the POS device sees neither.
 */
enum ProductStatus: string
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
