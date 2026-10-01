<?php

declare(strict_types=1);

namespace App\Support;

/**
 * LAUNCH-P1 P1-18 — a merchant's business activities: at least one, and
 * exactly one of them marked primary. Shared by the create-merchant and
 * the edit-activities requests so both answer 422 with the same message.
 */
final class BusinessActivitySelection
{
    public const PRIMARY_MESSAGE = 'Choose at least one business activity and mark exactly one of them as primary.';

    public static function hasExactlyOnePrimary(mixed $activities): bool
    {
        if (! is_array($activities) || $activities === []) {
            return false;
        }

        $primaries = 0;
        foreach ($activities as $activity) {
            if (is_array($activity) && filter_var($activity['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $primaries++;
            }
        }

        return $primaries === 1;
    }
}
