<?php

declare(strict_types=1);

/**
 * Feature tests for the competitor advisory
 * (/admin/api/v1/marketing/sliders/check-conflicts). A slider ad conflicts when
 * it targets a branch of a merchant whose own linked advertiser competes in the
 * same category.
 */

use App\Enums\PlatformRole;
use App\Models\Advertiser;
use App\Models\Branch;
use App\Models\Company;
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
function actingAsConflictRole(\Tests\TestCase $test, string $role): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole($role);
    $test->actingAs($user);

    return $user;
}

it('flags a competitor conflict in the same category', function (): void {
    actingAsConflictRole($this, PlatformRole::SuperAdmin->value);

    $merchant = Company::factory()->create(['name' => 'Coffee Co B']);
    $branch = Branch::factory()->create(['company_id' => $merchant->id]);
    $merchantAd = Advertiser::factory()->create(['brand_name' => 'Brand B', 'category' => 'coffee', 'is_merchant' => true, 'company_id' => $merchant->id]);
    $sliderAd = Advertiser::factory()->create(['brand_name' => 'Brand A', 'category' => 'Coffee']); // case-insensitive

    $res = $this->postJson('/admin/api/v1/marketing/sliders/check-conflicts', [
        'advertiser_ids' => [$sliderAd->id],
        'branch_ids' => [$branch->id],
    ])->assertOk();

    expect($res->json('data.conflicts'))->toHaveCount(1);
    expect($res->json('data.conflicts.0.advertiser_brand'))->toBe('Brand A');
    expect($res->json('data.conflicts.0.competitor_brand'))->toBe('Brand B');
    expect($res->json('data.conflicts.0.merchant_name'))->toBe('Coffee Co B');
    expect($merchantAd->category)->toBe('coffee');
});

it('does not flag different categories', function (): void {
    actingAsConflictRole($this, PlatformRole::SuperAdmin->value);

    $merchant = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $merchant->id]);
    Advertiser::factory()->create(['category' => 'fashion', 'is_merchant' => true, 'company_id' => $merchant->id]);
    $sliderAd = Advertiser::factory()->create(['category' => 'coffee']);

    $res = $this->postJson('/admin/api/v1/marketing/sliders/check-conflicts', [
        'advertiser_ids' => [$sliderAd->id],
        'branch_ids' => [$branch->id],
    ])->assertOk();

    expect($res->json('data.conflicts'))->toHaveCount(0);
});

it('does not flag a brand on its own store', function (): void {
    actingAsConflictRole($this, PlatformRole::SuperAdmin->value);

    $merchant = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $merchant->id]);
    $ownAd = Advertiser::factory()->create(['category' => 'coffee', 'is_merchant' => true, 'company_id' => $merchant->id]);

    $res = $this->postJson('/admin/api/v1/marketing/sliders/check-conflicts', [
        'advertiser_ids' => [$ownAd->id],
        'branch_ids' => [$branch->id],
    ])->assertOk();

    expect($res->json('data.conflicts'))->toHaveCount(0);
});

it('does not flag when the advertiser has no category', function (): void {
    actingAsConflictRole($this, PlatformRole::SuperAdmin->value);

    $merchant = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $merchant->id]);
    Advertiser::factory()->create(['category' => 'coffee', 'is_merchant' => true, 'company_id' => $merchant->id]);
    $sliderAd = Advertiser::factory()->create(['category' => null]);

    $res = $this->postJson('/admin/api/v1/marketing/sliders/check-conflicts', [
        'advertiser_ids' => [$sliderAd->id],
        'branch_ids' => [$branch->id],
    ])->assertOk();

    expect($res->json('data.conflicts'))->toHaveCount(0);
});

// ============ "everywhere" — the case that was never checked ============
// A slider with NO targets plays on EVERY merchant's screens. The old code
// short-circuited on an empty branch list, so the widest-reach configuration
// was the one configuration that never produced a warning.

it('flags competitors across ALL merchants when the slider targets nothing', function (): void {
    actingAsConflictRole($this, PlatformRole::SuperAdmin->value);

    // Two unrelated coffee merchants, neither one explicitly targeted.
    $merchantA = Company::factory()->create(['name' => 'Coffee Co A']);
    Branch::factory()->create(['company_id' => $merchantA->id]);
    Advertiser::factory()->create(['brand_name' => 'Brand A', 'category' => 'coffee', 'is_merchant' => true, 'company_id' => $merchantA->id]);

    $merchantB = Company::factory()->create(['name' => 'Coffee Co B']);
    Branch::factory()->create(['company_id' => $merchantB->id]);
    Advertiser::factory()->create(['brand_name' => 'Brand B', 'category' => 'coffee', 'is_merchant' => true, 'company_id' => $merchantB->id]);

    $sliderAd = Advertiser::factory()->create(['brand_name' => 'Third Coffee', 'category' => 'coffee']);

    $res = $this->postJson('/admin/api/v1/marketing/sliders/check-conflicts', [
        'advertiser_ids' => [$sliderAd->id],
        'branch_ids' => [],
        'everywhere' => true,
    ])->assertOk();

    // Both merchants are competitors of the slider's advertiser.
    $brands = collect($res->json('data.conflicts'))->pluck('competitor_brand')->sort()->values()->all();
    expect($brands)->toBe(['Brand A', 'Brand B']);
});

it('still reports nothing for an empty target list when NOT everywhere', function (): void {
    actingAsConflictRole($this, PlatformRole::SuperAdmin->value);

    $merchant = Company::factory()->create();
    Branch::factory()->create(['company_id' => $merchant->id]);
    Advertiser::factory()->create(['category' => 'coffee', 'is_merchant' => true, 'company_id' => $merchant->id]);
    $sliderAd = Advertiser::factory()->create(['category' => 'coffee']);

    // everywhere omitted ⇒ false ⇒ an empty branch list means "nothing picked
    // yet", not "the whole estate". Unchanged legacy behaviour.
    $res = $this->postJson('/admin/api/v1/marketing/sliders/check-conflicts', [
        'advertiser_ids' => [$sliderAd->id],
        'branch_ids' => [],
    ])->assertOk();

    expect($res->json('data.conflicts'))->toHaveCount(0);
});

it('does not flag the advertiser against its own stores when everywhere', function (): void {
    actingAsConflictRole($this, PlatformRole::SuperAdmin->value);

    // The advertiser IS a merchant: its own branches must never be a conflict.
    $ownCompany = Company::factory()->create(['name' => 'Own Coffee']);
    Branch::factory()->create(['company_id' => $ownCompany->id]);
    $selfAd = Advertiser::factory()->create(['brand_name' => 'Own Brand', 'category' => 'coffee', 'is_merchant' => true, 'company_id' => $ownCompany->id]);

    $res = $this->postJson('/admin/api/v1/marketing/sliders/check-conflicts', [
        'advertiser_ids' => [$selfAd->id],
        'branch_ids' => [],
        'everywhere' => true,
    ])->assertOk();

    expect($res->json('data.conflicts'))->toHaveCount(0);
});

it('forbids a Support role from the conflict check', function (): void {
    actingAsConflictRole($this, PlatformRole::Support->value);

    $this->postJson('/admin/api/v1/marketing/sliders/check-conflicts', [
        'advertiser_ids' => [],
        'branch_ids' => [],
    ])->assertForbidden();
});
