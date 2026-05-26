<?php

declare(strict_types=1);

/**
 * Feature tests for the platform Settings endpoints.
 *
 * Covers:
 *   - INDEX: returns the seeded catalogue, server-sorted by
 *     (group_key, display_order).
 *   - UPDATE: persists, busts the cache, type-coerces, writes one
 *     audit event per actually-changed key.
 *   - UNKNOWN KEY: 422 with the action's RuntimeException message.
 *   - PERMISSION: read is open to any authed admin; write requires
 *     SettingsManage. 401 unauthenticated.
 *   - HELPER: Setting::get() + Setting::set() round-trip + cache
 *     invalidation.
 */

use App\Enums\PlatformRole;
use App\Enums\UserType;
use App\Models\Setting;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
    $this->seed(SettingsSeeder::class);
});

function actingAsSettingsAdmin(\Tests\TestCase $test, string $role = 'platform_super_admin'): User
{
    /** @var User $user */
    $user = User::factory()->create(['user_type' => UserType::PlatformAdmin]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole($role);
    $test->actingAs($user);

    return $user;
}

// ============================ INDEX ================================

it('returns the seeded settings catalogue', function (): void {
    actingAsSettingsAdmin($this);

    $response = $this->getJson('/admin/api/v1/settings')
        ->assertOk()
        ->assertJsonStructure(['data' => [['key', 'value', 'type', 'group_key', 'label_en', 'options']]]);

    $keys = collect($response->json('data'))->pluck('key')->all();
    expect($keys)->toContain(
        'general.platform_name_en',
        'general.support_email',
        'localization.default_locale',
        'merchant_defaults.geofence_radius_m',
        'notifications.low_battery_threshold_pct',
        'maintenance.enabled',
    );
});

it('is readable by any authenticated admin (no specific permission)', function (): void {
    // Walk every seeded role and assert each gets the catalogue.
    foreach (PlatformRole::cases() as $role) {
        actingAsSettingsAdmin($this, $role->value);
        $this->getJson('/admin/api/v1/settings')->assertOk();
    }
});

it('requires authentication on index', function (): void {
    $this->getJson('/admin/api/v1/settings')->assertUnauthorized();
});

// =========================== UPDATE ================================

it('updates settings and busts the cache', function (): void {
    actingAsSettingsAdmin($this);

    // Warm the cache so we can prove it gets busted.
    expect(Setting::get('general.support_email'))->toBe('support@mithqal.local');

    $this->patchJson('/admin/api/v1/settings', [
        'settings' => [
            'general.support_email' => 'hello@mithqal.test',
            'merchant_defaults.geofence_radius_m' => 750,
        ],
    ])->assertOk();

    // Cached values reflect the new state immediately (the action
    // calls Setting::set() which forgets the cache key).
    expect(Setting::get('general.support_email'))->toBe('hello@mithqal.test');
    expect(Setting::get('merchant_defaults.geofence_radius_m'))->toBe(750);

    // Audit event per change.
    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'setting.updated',
    ]);
    expect(\App\Models\AuditLog::query()->where('event', 'setting.updated')->count())->toBe(2);
});

it('coerces types (integer strings become ints, "1" becomes true)', function (): void {
    actingAsSettingsAdmin($this);

    $this->patchJson('/admin/api/v1/settings', [
        'settings' => [
            'merchant_defaults.geofence_radius_m' => '1200',
            'merchant_defaults.require_2fa_for_super_admin' => '1',
        ],
    ])->assertOk();

    expect(Setting::get('merchant_defaults.geofence_radius_m'))->toBe(1200);
    expect(Setting::get('merchant_defaults.require_2fa_for_super_admin'))->toBe(true);
});

it('skips audit log for no-op writes (same value)', function (): void {
    actingAsSettingsAdmin($this);

    $this->patchJson('/admin/api/v1/settings', [
        'settings' => [
            // Already 'support@mithqal.local' per the seeder.
            'general.support_email' => 'support@mithqal.local',
        ],
    ])->assertOk();

    expect(\App\Models\AuditLog::query()->where('event', 'setting.updated')->count())->toBe(0);
});

it('422s on unknown setting key', function (): void {
    actingAsSettingsAdmin($this);

    $this->patchJson('/admin/api/v1/settings', [
        'settings' => [
            'wizardry.enabled' => true,
        ],
    ])->assertStatus(422)
        ->assertJsonPath('message', 'Unknown setting key: wizardry.enabled');
});

it('forbids update without settings.manage permission', function (): void {
    // Support has only *View / AuditLogsView permissions.
    actingAsSettingsAdmin($this, PlatformRole::Support->value);

    $this->patchJson('/admin/api/v1/settings', [
        'settings' => ['general.support_email' => 'x@x.x'],
    ])->assertForbidden();
});

// =========================== HELPERS ===============================

it('Setting::get + set round-trip + cache invalidation', function (): void {
    // Read (warms cache)
    expect(Setting::get('general.timezone'))->toBe('Asia/Muscat');
    expect(Cache::has('pos_settings:general.timezone'))->toBeTrue();

    // Write
    Setting::set('general.timezone', 'Asia/Riyadh');

    // Cache busted
    expect(Cache::has('pos_settings:general.timezone'))->toBeFalse();
    // Next read hits the DB + repopulates with the new value
    expect(Setting::get('general.timezone'))->toBe('Asia/Riyadh');
});

it('Setting::get falls back to the supplied default when key is missing', function (): void {
    expect(Setting::get('nonexistent.key', 'fallback'))->toBe('fallback');
});
