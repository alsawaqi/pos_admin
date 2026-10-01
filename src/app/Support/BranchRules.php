<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Validation\Validator;

/**
 * LAUNCH-P1 P1-17 — branch location and opening-hours rules shared by the
 * create and update branch requests.
 *
 *  - Geofence radius: 500–2000 m (blueprint §4.3.2 / §9.4).
 *  - Location: the admin must set it explicitly. The branch form's map
 *    used to start at a Muscat pin that was saved when nobody moved it;
 *    those exact coordinates are refused so a branch is never silently
 *    fenced around the wrong place.
 *  - Opening hours: days are mon..sun, times are real HH:MM (00:00–23:59).
 *    A day may pass midnight (close earlier than open = the next day,
 *    e.g. 18:00–01:00); an identical open and close time is refused
 *    unless the day is marked closed.
 */
final class BranchRules
{
    public const MIN_RADIUS_M = 500;

    public const MAX_RADIUS_M = 2000;

    /** The old branch map's default centre (Muscat). */
    public const UNSET_MAP_PIN = [23.5859, 58.4059];

    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    private const TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public static function radiusRule(): string
    {
        return 'between:'.self::MIN_RADIUS_M.','.self::MAX_RADIUS_M;
    }

    public static function isUnsetMapPin(mixed $latitude, mixed $longitude): bool
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return false;
        }

        return abs((float) $latitude - self::UNSET_MAP_PIN[0]) < 0.0000001
            && abs((float) $longitude - self::UNSET_MAP_PIN[1]) < 0.0000001;
    }

    public static function validateLocation(Validator $validator, mixed $latitude, mixed $longitude): void
    {
        if (self::isUnsetMapPin($latitude, $longitude)) {
            $validator->errors()->add(
                'latitude',
                'Set the branch location: click its position on the map or type its coordinates. The default map pin cannot be saved.',
            );
        }
    }

    public static function validateOpeningHours(Validator $validator, mixed $hours): void
    {
        if ($hours === null) {
            return;
        }

        if (! is_array($hours)) {
            return; // the `array` rule already reports this
        }

        foreach ($hours as $day => $entry) {
            $key = "opening_hours_json.{$day}";

            if (! in_array($day, self::DAYS, true)) {
                $validator->errors()->add($key, "Unknown day \"{$day}\". Use mon, tue, wed, thu, fri, sat or sun.");

                continue;
            }

            if (! is_array($entry)) {
                $validator->errors()->add($key, 'Each day needs an open time, a close time, or closed.');

                continue;
            }

            if (filter_var($entry['closed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            $open = $entry['open'] ?? null;
            $close = $entry['close'] ?? null;

            $openOk = is_string($open) && preg_match(self::TIME, $open) === 1;
            $closeOk = is_string($close) && preg_match(self::TIME, $close) === 1;

            if (! $openOk) {
                $validator->errors()->add("{$key}.open", 'Enter a valid opening time (HH:MM, 00:00 to 23:59).');
            }
            if (! $closeOk) {
                $validator->errors()->add("{$key}.close", 'Enter a valid closing time (HH:MM, 00:00 to 23:59).');
            }

            // Owner follow-up 2026-10-01: a closing time EARLIER than the
            // opening time means the next day (18:00–01:00 is valid).
            // Only an identical open and close time is meaningless.
            if ($openOk && $closeOk && $close === $open) {
                $validator->errors()->add("{$key}.close", 'The closing time cannot be the same as the opening time (or mark the day closed). A closing time before the opening time means the next day.');
            }
        }
    }
}
