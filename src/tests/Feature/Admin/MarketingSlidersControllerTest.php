<?php

declare(strict_types=1);

/**
 * Feature tests for the slider builder (/admin/api/v1/marketing/sliders).
 * Covers create (items + advertiser snapshot + a branch target), the builder
 * options endpoint, the "active needs items" guard, target validation, update
 * (full replace), show, soft-delete, and the permission gate.
 */

use App\Enums\PlatformRole;
use App\Models\Advertiser;
use App\Models\Branch;
use App\Models\ContentAsset;
use App\Models\Device;
use App\Models\MarketingSlider;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
});

/** Helper — log in as a platform admin with the given role. */
function actingAsSliderRole(\Tests\TestCase $test, string $role): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole($role);
    $test->actingAs($user);

    return $user;
}

it('creates a slider with ordered items, an advertiser snapshot, and a branch target', function (): void {
    actingAsSliderRole($this, PlatformRole::SuperAdmin->value);
    $advertiser = Advertiser::factory()->create();
    $a1 = ContentAsset::factory()->status('approved')->create(['advertiser_id' => $advertiser->id]);
    $a2 = ContentAsset::factory()->status('approved')->create(['advertiser_id' => $advertiser->id]);
    $branch = Branch::factory()->create();

    $res = $this->postJson('/admin/api/v1/marketing/sliders', [
        'name' => 'Summer Loop',
        'loop_interval_seconds' => 6,
        'status' => 'active',
        'items' => [
            ['content_asset_id' => $a1->id, 'duration_seconds' => 10],
            ['content_asset_id' => $a2->id],
        ],
        'targets' => [
            ['branch_id' => $branch->id],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Summer Loop')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.items_count', 2)
        ->assertJsonPath('data.targets_count', 1);

    $sliderId = $res->json('data.id');
    $this->assertDatabaseHas('pos_marketing_slider_items', [
        'slider_id' => $sliderId,
        'content_asset_id' => $a1->id,
        'advertiser_id' => $advertiser->id,
        'sort_order' => 0,
        'duration_seconds' => 10,
    ]);
    // Second item falls back to the loop interval for its duration.
    $this->assertDatabaseHas('pos_marketing_slider_items', [
        'slider_id' => $sliderId,
        'content_asset_id' => $a2->id,
        'sort_order' => 1,
        'duration_seconds' => 6,
    ]);
    $this->assertDatabaseHas('pos_marketing_slider_targets', [
        'slider_id' => $sliderId,
        'branch_id' => $branch->id,
    ]);
});

it('returns builder options: approved content + branches', function (): void {
    actingAsSliderRole($this, PlatformRole::SuperAdmin->value);
    $advertiser = Advertiser::factory()->create(['brand_name' => 'Lulu']);
    ContentAsset::factory()->status('approved')->create(['advertiser_id' => $advertiser->id]);
    ContentAsset::factory()->status('pending')->create(); // excluded
    $branch = Branch::factory()->create();

    $res = $this->getJson('/admin/api/v1/marketing/sliders/options')->assertOk();

    expect($res->json('data.content'))->toHaveCount(1);
    expect($res->json('data.content.0.advertiser.brand_name'))->toBe('Lulu');
    expect(collect($res->json('data.branches'))->pluck('id'))->toContain($branch->id);
});

it('returns devices with branch name, status, and an in-use hint', function (): void {
    actingAsSliderRole($this, PlatformRole::SuperAdmin->value);
    $branch = Branch::factory()->create(['name' => 'Main Branch']);
    $used = Device::factory()->create(['branch_id' => $branch->id, 'name' => 'Screen A']);
    $free = Device::factory()->create(['branch_id' => $branch->id, 'name' => 'Screen B']);

    // A slider already targeting $used → in_use = true for it.
    $slider = MarketingSlider::factory()->create();
    $slider->targets()->create(['device_id' => $used->id, 'branch_id' => $branch->id]);

    $res = $this->getJson('/admin/api/v1/marketing/sliders/options')->assertOk();
    $devices = collect($res->json('data.devices'))->keyBy('id');

    expect($devices[$used->id]['branch_name'])->toBe('Main Branch');
    expect($devices[$used->id]['in_use'])->toBeTrue();
    expect($devices[$free->id]['in_use'])->toBeFalse();
    expect($devices[$used->id])->toHaveKey('status');
});

it('creates a slider targeting specific devices', function (): void {
    actingAsSliderRole($this, PlatformRole::SuperAdmin->value);
    $asset = ContentAsset::factory()->status('approved')->create();
    $branch = Branch::factory()->create();
    $device = Device::factory()->create(['branch_id' => $branch->id]);

    $res = $this->postJson('/admin/api/v1/marketing/sliders', [
        'name' => 'Device Loop',
        'status' => 'active',
        'items' => [['content_asset_id' => $asset->id]],
        'targets' => [['device_id' => $device->id, 'branch_id' => $branch->id]],
    ])->assertCreated()->assertJsonPath('data.targets_count', 1);

    $this->assertDatabaseHas('pos_marketing_slider_targets', [
        'slider_id' => $res->json('data.id'),
        'device_id' => $device->id,
        'branch_id' => $branch->id,
    ]);
});

it('refuses to publish an active slider with no items', function (): void {
    actingAsSliderRole($this, PlatformRole::SuperAdmin->value);

    $this->postJson('/admin/api/v1/marketing/sliders', [
        'name' => 'Empty',
        'status' => 'active',
        'items' => [],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items']);
});

it('rejects a target branch that does not exist', function (): void {
    actingAsSliderRole($this, PlatformRole::SuperAdmin->value);
    $asset = ContentAsset::factory()->status('approved')->create();

    $this->postJson('/admin/api/v1/marketing/sliders', [
        'name' => 'Bad target',
        'status' => 'draft',
        'items' => [['content_asset_id' => $asset->id]],
        'targets' => [['branch_id' => 999999]],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['targets.0.branch_id']);
});

it('replaces items and targets on update', function (): void {
    actingAsSliderRole($this, PlatformRole::SuperAdmin->value);
    $slider = MarketingSlider::factory()->create();
    $a1 = ContentAsset::factory()->status('approved')->create();
    $a2 = ContentAsset::factory()->status('approved')->create();

    // Start with one item.
    $this->patchJson("/admin/api/v1/marketing/sliders/{$slider->uuid}", [
        'items' => [['content_asset_id' => $a1->id]],
    ])->assertOk()->assertJsonPath('data.items_count', 1);

    // Replace with two.
    $this->patchJson("/admin/api/v1/marketing/sliders/{$slider->uuid}", [
        'name' => 'Renamed',
        'items' => [
            ['content_asset_id' => $a2->id],
            ['content_asset_id' => $a1->id],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed')
        ->assertJsonPath('data.items_count', 2);

    expect(MarketingSlider::find($slider->id)->items()->count())->toBe(2);
});

it('shows a slider with its items and targets', function (): void {
    actingAsSliderRole($this, PlatformRole::SuperAdmin->value);
    $slider = MarketingSlider::factory()->create();
    $asset = ContentAsset::factory()->status('approved')->create();
    $slider->items()->create(['content_asset_id' => $asset->id, 'sort_order' => 0, 'duration_seconds' => 8]);

    $this->getJson("/admin/api/v1/marketing/sliders/{$slider->uuid}")
        ->assertOk()
        ->assertJsonPath('data.uuid', $slider->uuid)
        ->assertJsonPath('data.items.0.content.title', $asset->title);
});

it('soft-deletes a slider', function (): void {
    actingAsSliderRole($this, PlatformRole::SuperAdmin->value);
    $slider = MarketingSlider::factory()->create();

    $this->deleteJson("/admin/api/v1/marketing/sliders/{$slider->uuid}")->assertNoContent();

    $this->assertSoftDeleted('pos_marketing_sliders', ['id' => $slider->id]);
});

it('returns aggregated anonymous audience analytics for a slider', function (): void {
    actingAsSliderRole($this, PlatformRole::SuperAdmin->value);
    $branch = Branch::factory()->create(['name' => 'Mall Branch']);
    $slider = MarketingSlider::factory()->create();

    $row = fn (array $over): array => array_merge([
        'device_id' => 1, 'company_id' => 1, 'branch_id' => $branch->id,
        'slider_id' => $slider->id, 'slider_item_id' => 1, 'content_asset_id' => 1,
        'advertiser_id' => null, 'play_duration_ms' => 8000,
        'client_event_id' => (string) Str::uuid(), 'played_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ], $over);

    DB::table('pos_marketing_impressions')->insert([
        $row(['viewers_peak' => 5, 'viewers_avg' => 3, 'viewers_distinct' => 7, 'attention_ms' => 6000]),
        $row(['viewers_peak' => 2, 'viewers_avg' => 1, 'viewers_distinct' => 3, 'attention_ms' => 1500]),
        // A play with audience measurement OFF (nulls) — a play, but not "measured".
        $row(['viewers_peak' => null, 'viewers_avg' => null, 'viewers_distinct' => null, 'attention_ms' => null]),
    ]);

    $res = $this->getJson("/admin/api/v1/marketing/sliders/{$slider->uuid}/audience")->assertOk();

    $res->assertJsonPath('data.summary.plays', 3)
        ->assertJsonPath('data.summary.measured_plays', 2)
        ->assertJsonPath('data.summary.viewers_distinct', 10) // 7 + 3
        ->assertJsonPath('data.summary.viewers_peak', 5)       // max
        ->assertJsonPath('data.summary.attention_seconds', 8); // round((6000+1500)/1000)

    expect($res->json('data.by_branch.0.branch_name'))->toBe('Mall Branch');
    expect($res->json('data.by_branch.0.viewers'))->toBe(10);
});

it('forbids a Support role from the slider builder', function (): void {
    actingAsSliderRole($this, PlatformRole::Support->value);

    $slider = MarketingSlider::factory()->create();

    $this->getJson('/admin/api/v1/marketing/sliders')->assertForbidden();
    $this->postJson('/admin/api/v1/marketing/sliders', ['name' => 'X', 'items' => []])->assertForbidden();
    $this->getJson("/admin/api/v1/marketing/sliders/{$slider->uuid}/audience")->assertForbidden();
});
