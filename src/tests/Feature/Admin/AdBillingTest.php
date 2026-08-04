<?php

declare(strict_types=1);

/**
 * Phase 5 — advertiser billing: rate cards + invoices metered from
 * DELIVERED impressions (pos_marketing_impressions).
 *
 * Covers:
 *   - Billing math for both pricing models (per-1000 plays / per screen-day)
 *   - Snapshot immutability (later rate change never moves an issued bill)
 *   - Overlapping-period guard + void-then-reissue
 *   - Zero-delivery / no-rate-card / paid-void lifecycle guards
 *   - Pending drill quantities + overlap flag
 *   - Per-branch lines summing to the invoice total
 *   - Permission gates (reports.view reads, settings.manage mutations)
 */

use App\Actions\Admin\AdBilling\AdInvoiceLinesAction;
use App\Actions\Admin\AdBilling\CreateAdInvoiceAction;
use App\Actions\Admin\AdBilling\SetAdRateCardAction;
use App\Enums\PlatformRole;
use App\Models\AdInvoice;
use App\Models\Advertiser;
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

function adBillingAdmin(\Tests\TestCase $test): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole(PlatformRole::SuperAdmin->value);
    $test->actingAs($user);

    return $user;
}

/** One delivered slide play. */
function adPlay(int $advertiserId, string $playedAt, int $deviceId = 1, ?int $branchId = 10, int $durationMs = 8000): void
{
    DB::table('pos_marketing_impressions')->insert([
        'device_id' => $deviceId,
        'company_id' => 100,
        'branch_id' => $branchId,
        'slider_id' => 1,
        'slider_item_id' => 1,
        'content_asset_id' => 1,
        'advertiser_id' => $advertiserId,
        'play_duration_ms' => $durationMs,
        'client_event_id' => (string) Str::uuid(),
        'played_at' => $playedAt,
        'created_at' => $playedAt,
        'updated_at' => $playedAt,
    ]);
}

function adCard(int $advertiserId, string $model, string $rate): void
{
    app(SetAdRateCardAction::class)->handle($advertiserId, $model, $rate, null);
}

it('bills per thousand impressions from delivered plays', function (): void {
    $advertiser = Advertiser::factory()->create();
    adCard($advertiser->id, 'per_thousand_impressions', '2.500');

    // 4 plays in June, 1 outside the window (must not count).
    adPlay($advertiser->id, '2026-06-02 10:00:00');
    adPlay($advertiser->id, '2026-06-10 11:00:00');
    adPlay($advertiser->id, '2026-06-20 12:00:00', deviceId: 2);
    adPlay($advertiser->id, '2026-06-30 23:30:00');
    adPlay($advertiser->id, '2026-07-01 00:10:00');

    $invoice = app(CreateAdInvoiceAction::class)->handle(
        $advertiser->id, now()->parse('2026-06-01'), now()->parse('2026-06-30'), null,
    );

    expect($invoice->impressions_count)->toBe(4);
    // 2.500 per 1000 × 4 plays = 0.010
    expect((string) $invoice->amount)->toBe('0.010');
    expect($invoice->play_seconds)->toBe(32); // 4 × 8s
    expect($invoice->status)->toBe(AdInvoice::STATUS_ISSUED);
});

it('bills per screen-day from distinct device+day pairs', function (): void {
    $advertiser = Advertiser::factory()->create();
    adCard($advertiser->id, 'per_day_per_screen', '1.500');

    // Device 1 on two days (many plays), device 2 on one day = 3 screen-days.
    adPlay($advertiser->id, '2026-06-02 10:00:00', deviceId: 1);
    adPlay($advertiser->id, '2026-06-02 15:00:00', deviceId: 1);
    adPlay($advertiser->id, '2026-06-03 10:00:00', deviceId: 1);
    adPlay($advertiser->id, '2026-06-02 10:00:00', deviceId: 2);

    $invoice = app(CreateAdInvoiceAction::class)->handle(
        $advertiser->id, now()->parse('2026-06-01'), now()->parse('2026-06-30'), null,
    );

    expect($invoice->screen_days)->toBe(3);
    expect((string) $invoice->amount)->toBe('4.500'); // 3 × 1.500
});

it('snapshots pricing: a later rate change never moves an issued bill', function (): void {
    $advertiser = Advertiser::factory()->create();
    adCard($advertiser->id, 'per_thousand_impressions', '2.500');
    adPlay($advertiser->id, '2026-06-02 10:00:00');

    $invoice = app(CreateAdInvoiceAction::class)->handle(
        $advertiser->id, now()->parse('2026-06-01'), now()->parse('2026-06-30'), null,
    );

    adCard($advertiser->id, 'per_day_per_screen', '9.999');

    $fresh = AdInvoice::query()->findOrFail($invoice->id);
    expect((string) $fresh->rate)->toBe('2.500');
    expect($fresh->pricing_model)->toBe('per_thousand_impressions');
});

it('refuses an overlapping period and allows re-issue after void', function (): void {
    $advertiser = Advertiser::factory()->create();
    adCard($advertiser->id, 'per_thousand_impressions', '2.500');
    adPlay($advertiser->id, '2026-06-15 10:00:00');

    $first = app(CreateAdInvoiceAction::class)->handle(
        $advertiser->id, now()->parse('2026-06-01'), now()->parse('2026-06-30'), null,
    );

    // Overlap (even partial) refused.
    expect(fn () => app(CreateAdInvoiceAction::class)->handle(
        $advertiser->id, now()->parse('2026-06-20'), now()->parse('2026-07-20'), null,
    ))->toThrow(RuntimeException::class, 'already covers');

    // Void → the period is re-issuable.
    app(App\Actions\Admin\AdBilling\VoidAdInvoiceAction::class)->handle($first, null, 'wrong period');
    $second = app(CreateAdInvoiceAction::class)->handle(
        $advertiser->id, now()->parse('2026-06-01'), now()->parse('2026-06-30'), null,
    );
    expect($second->status)->toBe(AdInvoice::STATUS_ISSUED);
});

it('guards the lifecycle: no card, no delivery, paid-then-void', function (): void {
    $advertiser = Advertiser::factory()->create();

    // No rate card.
    expect(fn () => app(CreateAdInvoiceAction::class)->handle(
        $advertiser->id, now()->parse('2026-06-01'), now()->parse('2026-06-30'), null,
    ))->toThrow(RuntimeException::class, 'no active rate card');

    // No delivery.
    adCard($advertiser->id, 'per_thousand_impressions', '2.500');
    expect(fn () => app(CreateAdInvoiceAction::class)->handle(
        $advertiser->id, now()->parse('2026-06-01'), now()->parse('2026-06-30'), null,
    ))->toThrow(RuntimeException::class, 'Nothing was delivered');

    // Paid invoices cannot be voided.
    adPlay($advertiser->id, '2026-06-02 10:00:00');
    $invoice = app(CreateAdInvoiceAction::class)->handle(
        $advertiser->id, now()->parse('2026-06-01'), now()->parse('2026-06-30'), null,
    );
    app(App\Actions\Admin\AdBilling\MarkAdInvoicePaidAction::class)->handle($invoice, null);
    expect(fn () => app(App\Actions\Admin\AdBilling\VoidAdInvoiceAction::class)->handle($invoice->fresh(), null))
        ->toThrow(RuntimeException::class, 'paid invoice');
});

it('drills pending billing with quantities, estimate and the overlap flag', function (): void {
    adBillingAdmin($this);
    $billed = Advertiser::factory()->create(['name' => 'Billed Co']);
    $fresh = Advertiser::factory()->create(['name' => 'Fresh Co']);
    adCard($billed->id, 'per_thousand_impressions', '2.500');
    adCard($fresh->id, 'per_day_per_screen', '1.000');

    adPlay($billed->id, '2026-06-02 10:00:00');
    adPlay($fresh->id, '2026-06-03 10:00:00', deviceId: 3);
    app(CreateAdInvoiceAction::class)->handle(
        $billed->id, now()->parse('2026-06-01'), now()->parse('2026-06-30'), null,
    );

    $rows = $this->getJson('/admin/api/v1/ad-billing/pending?from=2026-06-01&to=2026-06-30')
        ->assertOk()->json('data');

    $byName = collect($rows)->keyBy('advertiser_name');
    expect($byName['Billed Co']['has_overlapping_invoice'])->toBeTrue();
    expect($byName['Fresh Co']['has_overlapping_invoice'])->toBeFalse();
    expect($byName['Fresh Co']['screen_days'])->toBe(1);
    expect($byName['Fresh Co']['estimated_amount'])->toBe('1.000');
});

it('derives per-branch lines that sum to the invoice total', function (): void {
    $advertiser = Advertiser::factory()->create();
    adCard($advertiser->id, 'per_thousand_impressions', '10.000');

    $company = App\Models\Company::factory()->create();
    $azaiba = App\Models\Branch::factory()->create(['company_id' => $company->id, 'name' => 'Azaiba']);
    $khuwair = App\Models\Branch::factory()->create(['company_id' => $company->id, 'name' => 'Khuwair']);
    adPlay($advertiser->id, '2026-06-02 10:00:00', deviceId: 1, branchId: $azaiba->id);
    adPlay($advertiser->id, '2026-06-02 11:00:00', deviceId: 1, branchId: $azaiba->id);
    adPlay($advertiser->id, '2026-06-02 12:00:00', deviceId: 2, branchId: $khuwair->id);

    $invoice = app(CreateAdInvoiceAction::class)->handle(
        $advertiser->id, now()->parse('2026-06-01'), now()->parse('2026-06-30'), null,
    );

    $lines = app(AdInvoiceLinesAction::class)->handle($invoice);
    expect($lines)->toHaveCount(2);
    $sum = array_sum(array_map(static fn (array $l): float => (float) $l['amount'], $lines));
    expect(number_format($sum, 3, '.', ''))->toBe((string) $invoice->amount);
    expect($lines[0]['branch_name'])->toBe('Azaiba'); // 2 plays > 1 play
});

it('rounds the amount DOWN — an invoice can never over-bill delivered value', function (): void {
    $advertiser = Advertiser::factory()->create();
    adCard($advertiser->id, 'per_thousand_impressions', '2.500');
    // ONE play: 2.500/1000 = 0.0025 OMR. Half-up would bill 0.003 — half a
    // baisa above delivered value; the never-over-bill guarantee demands
    // 0.002.
    adPlay($advertiser->id, '2026-06-02 10:00:00');

    $invoice = app(CreateAdInvoiceAction::class)->handle(
        $advertiser->id, now()->parse('2026-06-01'), now()->parse('2026-06-30'), null,
    );

    expect((string) $invoice->amount)->toBe('0.002');
});

it('attributes a moved device screen-day to ONE branch so lines sum to the invoice', function (): void {
    $advertiser = Advertiser::factory()->create();
    adCard($advertiser->id, 'per_day_per_screen', '1.500');

    $company = App\Models\Company::factory()->create();
    $a = App\Models\Branch::factory()->create(['company_id' => $company->id, 'name' => 'A']);
    $b = App\Models\Branch::factory()->create(['company_id' => $company->id, 'name' => 'B']);

    // Device 1 plays under branch A twice and branch B once on the SAME day
    // (reassigned mid-day). The invoice counts ONE screen-day; the lines
    // must attribute it to A (most plays) — not once per branch.
    adPlay($advertiser->id, '2026-06-02 09:00:00', deviceId: 1, branchId: $a->id);
    adPlay($advertiser->id, '2026-06-02 10:00:00', deviceId: 1, branchId: $a->id);
    adPlay($advertiser->id, '2026-06-02 15:00:00', deviceId: 1, branchId: $b->id);

    $invoice = app(CreateAdInvoiceAction::class)->handle(
        $advertiser->id, now()->parse('2026-06-01'), now()->parse('2026-06-30'), null,
    );
    expect($invoice->screen_days)->toBe(1);
    expect((string) $invoice->amount)->toBe('1.500');

    $lines = app(AdInvoiceLinesAction::class)->handle($invoice);
    $sum = array_sum(array_map(static fn (array $l): float => (float) $l['amount'], $lines));
    expect(number_format($sum, 3, '.', ''))->toBe('1.500');
    $byName = collect($lines)->keyBy('branch_name');
    expect($byName['A']['screen_days'])->toBe(1);
    expect($byName['B']['screen_days'])->toBe(0);
    // Plays still partition per row.
    expect($byName['A']['plays'])->toBe(2);
    expect($byName['B']['plays'])->toBe(1);
});

it('gates reads on reports.view and mutations on settings.manage', function (): void {
    // An authenticated user with NO platform role at all.
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->getJson('/admin/api/v1/ad-billing/pending?from=2026-06-01&to=2026-06-30')->assertForbidden();
    $this->postJson('/admin/api/v1/ad-billing/invoices', [
        'advertiser_id' => 1, 'from' => '2026-06-01', 'to' => '2026-06-30',
    ])->assertForbidden();
    $this->postJson('/admin/api/v1/ad-billing/rate-cards', [
        'advertiser_id' => 1, 'pricing_model' => 'per_day_per_screen', 'rate' => '1.000',
    ])->assertForbidden();
});

it('issues and manages invoices through the endpoints', function (): void {
    adBillingAdmin($this);
    $advertiser = Advertiser::factory()->create();

    $this->postJson('/admin/api/v1/ad-billing/rate-cards', [
        'advertiser_id' => $advertiser->id,
        'pricing_model' => 'per_thousand_impressions',
        'rate' => '2.500',
    ])->assertCreated();

    adPlay($advertiser->id, '2026-06-02 10:00:00');

    $uuid = $this->postJson('/admin/api/v1/ad-billing/invoices', [
        'advertiser_id' => $advertiser->id, 'from' => '2026-06-01', 'to' => '2026-06-30',
    ])->assertCreated()->json('data.uuid');

    $this->postJson("/admin/api/v1/ad-billing/invoices/{$uuid}/mark-paid")->assertOk();
    expect(AdInvoice::query()->where('uuid', $uuid)->value('status'))->toBe('paid');

    $list = $this->getJson('/admin/api/v1/ad-billing/invoices?status=paid')->assertOk()->json('data');
    expect($list)->toHaveCount(1);
});
