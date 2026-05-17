<?php

declare(strict_types=1);

use App\Enums\PlatformPermission;
use App\Enums\PlatformRole;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('seeds the platform permission catalog and roles', function (): void {
    $this->seed(PlatformRoleSeeder::class);

    foreach (PlatformPermission::values() as $permission) {
        $this->assertDatabaseHas(Permission::class, [
            'name' => $permission,
            'guard_name' => 'web',
        ]);
    }

    foreach (PlatformRole::values() as $role) {
        $this->assertDatabaseHas(Role::class, [
            'name' => $role,
            'guard_name' => 'web',
            'team_id' => null,
        ]);
    }
});

it('grants the super admin role every platform permission', function (): void {
    $this->seed(PlatformRoleSeeder::class);

    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    /** @var Role $superAdmin */
    $superAdmin = Role::query()
        ->where('name', PlatformRole::SuperAdmin->value)
        ->whereNull('team_id')
        ->firstOrFail();

    $expected = collect(PlatformPermission::values())->sort()->values();
    $actual = $superAdmin->permissions->pluck('name')->sort()->values();

    expect($actual->all())->toEqual($expected->all());
});
