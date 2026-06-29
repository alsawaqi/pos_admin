<?php

declare(strict_types=1);

/**
 * Feature tests for admin content review
 * (/admin/api/v1/marketing/content). Covers the pending vs reviewed lists, the
 * advertiser join, approve / reject (+ note + reviewed_at), the "only pending"
 * guard, and the permission gate.
 */

use App\Enums\PlatformRole;
use App\Models\Advertiser;
use App\Models\ContentAsset;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
});

/** Helper — log in as a platform admin with the given role. */
function actingAsContentRole(\Tests\TestCase $test, string $role): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole($role);
    $test->actingAs($user);

    return $user;
}

it('lists pending content by default', function (): void {
    actingAsContentRole($this, PlatformRole::SuperAdmin->value);
    ContentAsset::factory()->status('pending')->create();
    ContentAsset::factory()->status('approved')->create();

    $res = $this->getJson('/admin/api/v1/marketing/content')->assertOk();
    expect($res->json('data'))->toHaveCount(1);
    expect($res->json('data.0.status'))->toBe('pending');
});

it('lists reviewed content when view=reviewed', function (): void {
    actingAsContentRole($this, PlatformRole::SuperAdmin->value);
    ContentAsset::factory()->status('pending')->create();
    ContentAsset::factory()->status('approved')->create();
    ContentAsset::factory()->status('rejected')->create();
    ContentAsset::factory()->status('live')->create();

    $res = $this->getJson('/admin/api/v1/marketing/content?view=reviewed')->assertOk();
    expect($res->json('data'))->toHaveCount(3);
});

it('includes the advertiser brand', function (): void {
    actingAsContentRole($this, PlatformRole::SuperAdmin->value);
    $advertiser = Advertiser::factory()->create(['brand_name' => 'Lulu Mart']);
    ContentAsset::factory()->status('pending')->create(['advertiser_id' => $advertiser->id]);

    $res = $this->getJson('/admin/api/v1/marketing/content')->assertOk();
    expect($res->json('data.0.advertiser.brand_name'))->toBe('Lulu Mart');
});

it('approves pending content and stamps reviewed_at', function (): void {
    actingAsContentRole($this, PlatformRole::SuperAdmin->value);
    $asset = ContentAsset::factory()->status('pending')->create();

    $this->postJson("/admin/api/v1/marketing/content/{$asset->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $asset->refresh();
    expect($asset->status)->toBe('approved');
    expect($asset->reviewed_at)->not->toBeNull();
});

it('rejects pending content with a note', function (): void {
    actingAsContentRole($this, PlatformRole::SuperAdmin->value);
    $asset = ContentAsset::factory()->status('pending')->create();

    $this->postJson("/admin/api/v1/marketing/content/{$asset->id}/reject", ['note' => 'Logo too small.'])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.review_note', 'Logo too small.');

    $this->assertDatabaseHas('content_assets', [
        'id' => $asset->id,
        'status' => 'rejected',
        'review_note' => 'Logo too small.',
    ]);
});

it('refuses to review content that is not pending', function (): void {
    actingAsContentRole($this, PlatformRole::SuperAdmin->value);
    $asset = ContentAsset::factory()->status('approved')->create();

    $this->postJson("/admin/api/v1/marketing/content/{$asset->id}/approve")->assertStatus(422);
    $this->postJson("/admin/api/v1/marketing/content/{$asset->id}/reject")->assertStatus(422);
});

it('forbids a Support role from reviewing content', function (): void {
    actingAsContentRole($this, PlatformRole::Support->value);
    $asset = ContentAsset::factory()->status('pending')->create();

    $this->getJson('/admin/api/v1/marketing/content')->assertForbidden();
    $this->postJson("/admin/api/v1/marketing/content/{$asset->id}/approve")->assertForbidden();
});

it('groups submitters with pending counts and skips drafts', function (): void {
    actingAsContentRole($this, PlatformRole::SuperAdmin->value);
    $a = Advertiser::factory()->create(['brand_name' => 'Acme']);
    $b = Advertiser::factory()->create(['brand_name' => 'Globex']);

    ContentAsset::factory()->status('pending')->create(['advertiser_id' => $a->id]);
    ContentAsset::factory()->status('pending')->create(['advertiser_id' => $a->id]);
    ContentAsset::factory()->status('approved')->create(['advertiser_id' => $a->id]);
    ContentAsset::factory()->status('draft')->create(['advertiser_id' => $a->id]);   // excluded (not submitted)
    ContentAsset::factory()->status('approved')->create(['advertiser_id' => $b->id]); // submitted, none pending

    $res = $this->getJson('/admin/api/v1/marketing/content/submitters')->assertOk();
    $data = collect($res->json('data'));

    expect($data)->toHaveCount(2);
    // sorted by pending desc → Acme first
    expect($data->first()['brand_name'])->toBe('Acme');
    expect($data->first()['pending_count'])->toBe(2);
    expect($data->first()['total'])->toBe(3);

    $globex = $data->firstWhere('brand_name', 'Globex');
    expect($globex['pending_count'])->toBe(0);
    expect($globex['total'])->toBe(1);
});

it('forbids a Support role from listing submitters', function (): void {
    actingAsContentRole($this, PlatformRole::Support->value);

    $this->getJson('/admin/api/v1/marketing/content/submitters')->assertForbidden();
});
