<?php

declare(strict_types=1);

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\User;
use Database\Seeders\DefaultAdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('creates the configured default POS admin user', function (): void {
    config()->set('pos_admin_auth.default_admin.name', 'MITHQAL Admin');
    config()->set('pos_admin_auth.default_admin.email', 'admin@mithqal.test');
    config()->set('pos_admin_auth.default_admin.password', 'ChangeMe123!');

    $this->seed(DefaultAdminUserSeeder::class);

    $admin = User::query()
        ->where('email', 'admin@mithqal.test')
        ->first();

    expect($admin)->not->toBeNull()
        ->and($admin->name)->toBe('MITHQAL Admin')
        ->and($admin->user_type)->toBe(UserType::PlatformAdmin)
        ->and($admin->status)->toBe(UserStatus::Active)
        ->and(Hash::check('ChangeMe123!', $admin->password))->toBeTrue();
});

it('updates the default POS admin without duplicating it', function (): void {
    config()->set('pos_admin_auth.default_admin.name', 'MITHQAL Admin');
    config()->set('pos_admin_auth.default_admin.email', 'admin@mithqal.test');
    config()->set('pos_admin_auth.default_admin.password', 'FirstPassword123!');

    $this->seed(DefaultAdminUserSeeder::class);

    config()->set('pos_admin_auth.default_admin.name', 'MITHQAL Owner');
    config()->set('pos_admin_auth.default_admin.password', 'SecondPassword123!');

    $this->seed(DefaultAdminUserSeeder::class);

    expect(User::query()->where('email', 'admin@mithqal.test')->count())->toBe(1);

    $admin = User::query()
        ->where('email', 'admin@mithqal.test')
        ->firstOrFail();

    expect($admin->name)->toBe('MITHQAL Owner')
        ->and(Hash::check('SecondPassword123!', $admin->password))->toBeTrue();
});
