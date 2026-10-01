<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * LAUNCH-P1 B1 fixtures: since P1-9 a move to a new (company, branch) must
 * choose the round-up commission profile + organization, and since 2a a new
 * assignment defaults to 'branch' mode, which needs a branch with
 * coordinates (the factory branches have none, so older tests use 'any').
 */
final class DeviceAssignment
{
    public static function commissionProfile(string $name = 'Assign profile'): int
    {
        return (int) DB::table('commission_profiles')->insertGetId([
            'name' => $name, 'description' => null, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public static function organization(string $name = 'Assign organization'): int
    {
        return (int) DB::table('organizations')->insertGetId([
            'name' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{commission_profile_id: int, organization_id: int, location_mode: string} */
    public static function extras(string $locationMode = 'any'): array
    {
        return [
            'commission_profile_id' => self::commissionProfile(),
            'organization_id' => self::organization(),
            'location_mode' => $locationMode,
        ];
    }
}
