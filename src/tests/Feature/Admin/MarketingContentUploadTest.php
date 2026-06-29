<?php

declare(strict_types=1);

/**
 * Admin slider-media upload proxy: pos_admin forwards the file to marketing-api
 * (shared internal token), gated by marketing.sliders.manage. The marketing-api
 * call is faked here — this only verifies pos_admin's forwarding + the gate.
 */

use App\Enums\PlatformRole;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
    config([
        'services.internal.content_token' => 'tok',
        'services.marketing.api_url' => 'http://marketing-api-nginx-1',
    ]);
});

function actingAsUploadRole(\Tests\TestCase $test, string $role): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole($role);
    $test->actingAs($user);

    return $user;
}

it('forwards an admin slider-media upload to marketing-api with the internal token', function (): void {
    Http::fake([
        '*/api/admin/content-assets' => Http::response([
            'data' => ['id' => 99, 'type' => 'image', 'status' => 'approved', 'url' => 'http://m/a.jpg'],
        ], 201),
    ]);
    actingAsUploadRole($this, PlatformRole::SuperAdmin->value);

    $this->post('/admin/api/v1/marketing/content/upload', [
        'title' => 'Promo banner',
        'file' => UploadedFile::fake()->create('a.jpg', 80, 'image/jpeg'),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.id', 99);

    Http::assertSent(fn ($req): bool => str_contains($req->url(), '/api/admin/content-assets')
        && $req->hasHeader('X-Internal-Token', 'tok'));
});

it('forbids a Support role from uploading slider media', function (): void {
    actingAsUploadRole($this, PlatformRole::Support->value);

    $this->post('/admin/api/v1/marketing/content/upload', [
        'title' => 'X',
        'file' => UploadedFile::fake()->create('a.jpg', 80, 'image/jpeg'),
    ], ['Accept' => 'application/json'])
        ->assertForbidden();
});
