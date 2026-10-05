<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Branch;
use Illuminate\Support\Carbon;

/**
 * LAUNCH-P5 add-on — the branch "Location check" switch and its history,
 * kept like a device's 'any' periods (ChangeDeviceLocationModeAction):
 *
 *  - turning the check OFF stamps location_check_off_since = now;
 *  - turning it back ON appends the closed period {from: off_since, until:
 *    now} to location_check_off_windows (newest last, the last 50 kept) and
 *    clears off_since.
 *
 * pos_api skips the location rules for an event made inside any off period,
 * so a sale made offline while the check was off and synced after it was
 * turned back on is not refused.
 */
final class BranchLocationCheck
{
    /** Closed off periods kept per branch (newest win). */
    public const MAX_OFF_WINDOWS = 50;

    /** Fill the switch and its history on $branch (the caller saves). */
    public static function apply(Branch $branch, bool $enabled): void
    {
        $wasEnabled = $branch->exists ? $branch->getOriginal('location_check_enabled') !== false : true;
        if ($wasEnabled === $enabled) {
            $branch->location_check_enabled = $enabled;

            return;
        }
        $now = Carbon::now();
        if (! $enabled) {
            $branch->forceFill(['location_check_enabled' => false, 'location_check_off_since' => $now]);

            return;
        }
        $windows = is_array($branch->location_check_off_windows) ? $branch->location_check_off_windows : [];
        $from = $branch->location_check_off_since ?? $now;
        $windows[] = ['from' => $from->toIso8601String(), 'until' => $now->toIso8601String()];
        $branch->forceFill([
            'location_check_enabled' => true,
            'location_check_off_since' => null,
            'location_check_off_windows' => array_values(array_slice($windows, -self::MAX_OFF_WINDOWS)),
        ]);
    }
}
