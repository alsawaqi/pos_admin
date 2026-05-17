<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

class DefaultAdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $name = trim((string) config('pos_admin_auth.default_admin.name'));
        $email = Str::lower(trim((string) config('pos_admin_auth.default_admin.email')));
        $password = (string) config('pos_admin_auth.default_admin.password');

        if ($name === '' || $email === '' || $password === '') {
            throw new RuntimeException('Default POS admin name, email, and password must be configured.');
        }

        /** @var User $user */
        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password,
                'user_type' => UserType::PlatformAdmin,
                'status' => UserStatus::Active,
                'timezone' => 'Asia/Muscat',
                'locale' => 'en',
            ],
        );

        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $user->syncRoles([PlatformRole::SuperAdmin->value]);
    }
}
