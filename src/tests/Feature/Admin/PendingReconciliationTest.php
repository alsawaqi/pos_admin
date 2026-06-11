<?php

declare(strict_types=1);

use App\Enums\PlatformRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * P-F7 — Pending Reconciliation approval queue.
 *
 * A force-recorded (NFC-timeout) Soft POS tender lands
 * status=pending_reconciliation; its sale's MONEY effects (commission
 * split + charity round-up forwarding) are deferred by pos_api until the
 * admin approves the order here. These tests cover the order-centric list,
 * approve (flip + deferred effects, idempotent), reject (failed, no
 * effects), and the bank-file commit converging on the same effects.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
});

function pendingReconActingAs(\Tests\TestCase $test, string $role): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole($role);
    $test->actingAs($user);

    return $user;
}

/**
 * A paid order with one PENDING card tender (the force-recorded charge),
 * its own company/branch/device graph. Returns the created ids.
 *
 * @return array{company: Company, branch: Branch, device: Device, order_id: int, payment_id: int}
 */
function pendingReconSeedOrder(array $paymentAttrs = [], array $orderAttrs = []): array
{
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $device = Device::factory()->assigned()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'name' => 'POS-PENDING-1',
    ]);

    $orderId = DB::table('pos_orders')->insertGetId(array_merge([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'branch_id' => $branch->id,
        'order_type' => 'quick', 'status' => 'paid', 'source' => 'main_pos',
        'subtotal' => '5.000', 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => '5.000',
        'opened_at' => now(), 'closed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ], $orderAttrs));

    $paymentId = DB::table('pos_payments')->insertGetId(array_merge([
        'uuid' => (string) Str::uuid(), 'order_id' => $orderId, 'method' => 'card',
        'amount' => '5.000', 'status' => 'pending_reconciliation', 'pending_reconciliation' => true,
        'softpos_reference' => 'NFC-REF-1', 'softpos_auth_code' => 'A77',
        'bank_response' => json_encode(['status' => 'timeout']),
        'device_id' => $device->id, 'terminal_id' => $device->terminal_id,
        'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ], $paymentAttrs));

    return ['company' => $company, 'branch' => $branch, 'device' => $device, 'order_id' => $orderId, 'payment_id' => $paymentId];
}

/** The merchant's commission profile: platform 2% + bank 3% (merchant 95%). */
function pendingReconSeedProfile(int $companyId): int
{
    $profileId = (int) DB::table('pos_commission_profiles')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $companyId,
        'is_active' => true, 'merchant_percent' => 95,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ([['platform', 'Platform', 2, 0], ['bank', 'Acme Bank', 3, 1]] as [$type, $label, $percent, $sort]) {
        DB::table('pos_commission_shares')->insert([
            'commission_profile_id' => $profileId, 'party_type' => $type, 'label' => $label,
            'percent' => $percent, 'sort_order' => $sort, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $profileId;
}

/** An unforwarded round-up donation riding the order's card tender. */
function pendingReconSeedDonation(array $ctx, string $amount = '0.200'): int
{
    return (int) DB::table('pos_roundup_donations')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'company_id' => $ctx['company']->id, 'branch_id' => $ctx['branch']->id,
        'device_id' => $ctx['device']->id, 'order_id' => $ctx['order_id'], 'payment_id' => $ctx['payment_id'],
        'terminal_id' => $ctx['device']->terminal_id,
        'amount' => $amount, 'bank_response' => json_encode(['status' => 'timeout']),
        'status' => 'pending', 'source' => 'pos_roundup',
        'occurred_at' => now(), 'forwarded_at' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

// ─── List ────────────────────────────────────────────────────────────────

it('lists only orders with pending tenders for the requested day, across all merchants', function (): void {
    pendingReconActingAs($this, PlatformRole::SuperAdmin->value);

    // Two different merchants pending TODAY — the platform admin sees both.
    $a = pendingReconSeedOrder();
    $b = pendingReconSeedOrder();
    // Pending YESTERDAY — hidden behind the day filter.
    $yesterday = pendingReconSeedOrder(['captured_at' => now()->subDay()]);
    // Fully settled order today — never listed.
    $settled = pendingReconSeedOrder(['status' => 'success', 'pending_reconciliation' => false]);

    $res = $this->getJson('/admin/api/v1/pending-reconciliation')->assertOk();

    $ids = collect($res->json('data'))->pluck('id')->all();
    expect($ids)->toContain($a['order_id'])
        ->toContain($b['order_id'])
        ->not->toContain($yesterday['order_id'])
        ->not->toContain($settled['order_id']);
    expect($res->json('totals.orders'))->toBe(2);
    expect($res->json('totals.pending_amount'))->toBe('10.000');

    // Evidence columns ride along.
    $row = collect($res->json('data'))->firstWhere('id', $a['order_id']);
    expect($row['company']['name'])->toBe($a['company']->name);
    expect($row['device_name'])->toBe('POS-PENDING-1');
    expect($row['pending_total'])->toBe('5.000');
    expect($row['tenders'][0]['softpos_reference'])->toBe('NFC-REF-1');
    expect($row['tenders'][0]['bank_verdict'])->toBe('timeout');

    // The ?date filter reaches yesterday's force-record.
    $res = $this->getJson('/admin/api/v1/pending-reconciliation?date='.now()->subDay()->toDateString())->assertOk();
    expect(collect($res->json('data'))->pluck('id')->all())->toBe([$yesterday['order_id']]);
});

it('forbids a non-settings user', function (): void {
    pendingReconActingAs($this, PlatformRole::Support->value);

    $this->getJson('/admin/api/v1/pending-reconciliation')->assertForbidden();
    $this->postJson('/admin/api/v1/pending-reconciliation/approve', ['order_ids' => [1]])->assertForbidden();
    $this->postJson('/admin/api/v1/pending-reconciliation/reject', ['order_ids' => [1]])->assertForbidden();
});

// ─── Approve ─────────────────────────────────────────────────────────────

it('approve flips the tenders, records the commission once, forwards the round-up, and audits', function (): void {
    config(['services.charity.url' => 'http://charity.test']);
    Http::fake(['*' => Http::response(['success' => true], 201)]);

    $admin = pendingReconActingAs($this, PlatformRole::SuperAdmin->value);
    $ctx = pendingReconSeedOrder();
    $profileId = pendingReconSeedProfile($ctx['company']->id);
    $donationId = pendingReconSeedDonation($ctx);

    $res = $this->postJson('/admin/api/v1/pending-reconciliation/approve', ['order_ids' => [$ctx['order_id']]])
        ->assertOk();

    expect($res->json('data.orders_approved'))->toBe(1);
    expect($res->json('data.payments_reconciled'))->toBe(1);
    expect($res->json('data.effects.commissions_recorded'))->toBe(1);
    expect($res->json('data.effects.donations_forwarded'))->toBe(1);
    expect($res->json('data.effects.donation_forward_failures'))->toBe([]);

    // Tender settled exactly like the bank-file path.
    $this->assertDatabaseHas('pos_payments', [
        'id' => $ctx['payment_id'], 'status' => 'success', 'pending_reconciliation' => false,
        'reconciled_by_admin_id' => $admin->id,
    ]);

    // Commission split: platform 2% of 5.000 = 0.100, bank 3% of the 5.000
    // card money = 0.150, merchant remainder 4.750 — like PayOrderHandler.
    $rows = DB::table('pos_sale_commissions')->where('order_id', $ctx['order_id'])->orderBy('sort_order')->get();
    expect($rows)->toHaveCount(3);
    expect((float) $rows[0]->commission_amount)->toBe(0.100);
    expect((float) $rows[1]->commission_amount)->toBe(0.150);
    expect($rows[2]->party_type)->toBe('merchant');
    expect((float) $rows[2]->commission_amount)->toBe(4.750);
    expect((int) $rows[0]->commission_profile_id)->toBe($profileId);
    expect((int) $rows[0]->device_id)->toBe($ctx['device']->id);

    // Round-up forwarded to the charity app + stamped.
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/donations-pos-roundup')
        && $request['pos_device_id'] === $ctx['device']->id
        && $request['amount'] === '0.200');
    expect(DB::table('pos_roundup_donations')->find($donationId)->forwarded_at)->not->toBeNull();

    // Decision trail.
    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'payment.reconciled', 'auditable_id' => $ctx['payment_id']]);
    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'order.reconciliation_approved', 'auditable_id' => $ctx['order_id']]);

    // Replaying the approval (double-click / retry) splits NOTHING twice.
    $res = $this->postJson('/admin/api/v1/pending-reconciliation/approve', ['order_ids' => [$ctx['order_id']]])
        ->assertOk();
    expect($res->json('data.effects.commissions_recorded'))->toBe(0);
    expect(DB::table('pos_sale_commissions')->where('order_id', $ctx['order_id'])->count())->toBe(3);
    expect(DB::table('pos_roundup_donations')->where('order_id', $ctx['order_id'])->whereNull('forwarded_at')->count())->toBe(0);
});

it('approve still settles when the charity forward fails, and surfaces the failure', function (): void {
    config(['services.charity.url' => 'http://charity.test']);
    Http::fake(['*' => Http::response(['success' => false, 'message' => 'down'], 500)]);

    pendingReconActingAs($this, PlatformRole::SuperAdmin->value);
    $ctx = pendingReconSeedOrder();
    pendingReconSeedProfile($ctx['company']->id);
    $donationId = pendingReconSeedDonation($ctx);

    $res = $this->postJson('/admin/api/v1/pending-reconciliation/approve', ['order_ids' => [$ctx['order_id']]])
        ->assertOk();

    // The approval itself sticks (payment settled, commission recorded)…
    $this->assertDatabaseHas('pos_payments', ['id' => $ctx['payment_id'], 'status' => 'success']);
    expect($res->json('data.effects.commissions_recorded'))->toBe(1);

    // …the failed forward is reported and left retryable (marker NULL).
    expect($res->json('data.effects.donations_forwarded'))->toBe(0);
    expect($res->json('data.effects.donation_forward_failures.0.donation_id'))->toBe($donationId);
    expect(DB::table('pos_roundup_donations')->find($donationId)->forwarded_at)->toBeNull();
});

// ─── Reject ──────────────────────────────────────────────────────────────

it('reject marks the tenders failed, audits, and fires no money effects', function (): void {
    config(['services.charity.url' => 'http://charity.test']);
    Http::fake();

    $admin = pendingReconActingAs($this, PlatformRole::SuperAdmin->value);
    $ctx = pendingReconSeedOrder();
    pendingReconSeedProfile($ctx['company']->id);
    $donationId = pendingReconSeedDonation($ctx);

    $res = $this->postJson('/admin/api/v1/pending-reconciliation/reject', ['order_ids' => [$ctx['order_id']]])
        ->assertOk();

    expect($res->json('data.orders_rejected'))->toBe(1);
    expect($res->json('data.payments_failed'))->toBe(1);

    // The money never arrived: tender failed, decision stamped — but the
    // order itself is untouched (voiding goes through the normal flows).
    $this->assertDatabaseHas('pos_payments', [
        'id' => $ctx['payment_id'], 'status' => 'failed', 'pending_reconciliation' => false,
        'reconciled_by_admin_id' => $admin->id,
    ]);
    $this->assertDatabaseHas('pos_orders', ['id' => $ctx['order_id'], 'status' => 'paid']);

    // No deferred effect fired.
    expect(DB::table('pos_sale_commissions')->count())->toBe(0);
    Http::assertNothingSent();
    expect(DB::table('pos_roundup_donations')->find($donationId)->forwarded_at)->toBeNull();

    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'payment.reconciliation_rejected', 'auditable_id' => $ctx['payment_id']]);

    // And it left the queue.
    $res = $this->getJson('/admin/api/v1/pending-reconciliation')->assertOk();
    expect(collect($res->json('data'))->pluck('id')->all())->not->toContain($ctx['order_id']);
});

// ─── Bank-file convergence ───────────────────────────────────────────────

it('the bank-file commit fires the same deferred effects', function (): void {
    config(['services.charity.url' => 'http://charity.test']);
    Http::fake(['*' => Http::response(['success' => true], 201)]);

    pendingReconActingAs($this, PlatformRole::SuperAdmin->value);
    $ctx = pendingReconSeedOrder();
    pendingReconSeedProfile($ctx['company']->id);
    $donationId = pendingReconSeedDonation($ctx);

    // The existing matching tool settles by PAYMENT ids — same effects.
    $this->postJson('/admin/api/v1/bank-reconciliation/commit', ['payment_ids' => [$ctx['payment_id']]])
        ->assertOk()
        ->assertJsonPath('data.reconciled', 1);

    $this->assertDatabaseHas('pos_payments', ['id' => $ctx['payment_id'], 'status' => 'success', 'pending_reconciliation' => false]);
    expect(DB::table('pos_sale_commissions')->where('order_id', $ctx['order_id'])->count())->toBe(3);
    expect(DB::table('pos_roundup_donations')->find($donationId)->forwarded_at)->not->toBeNull();
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/donations-pos-roundup'));
});

// ─── Split tender: effects wait for the LAST pending half ───────────────

it('a split with another still-pending tender defers the effects until both settle', function (): void {
    pendingReconActingAs($this, PlatformRole::SuperAdmin->value);
    $ctx = pendingReconSeedOrder(['amount' => '3.000']);
    pendingReconSeedProfile($ctx['company']->id);

    // A second, separate pending card tender on the same order.
    $secondId = (int) DB::table('pos_payments')->insertGetId([
        'uuid' => (string) Str::uuid(), 'order_id' => $ctx['order_id'], 'method' => 'card',
        'amount' => '2.000', 'status' => 'pending_reconciliation', 'pending_reconciliation' => true,
        'device_id' => $ctx['device']->id, 'terminal_id' => $ctx['device']->terminal_id,
        'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    // Settle only the FIRST half through the bank-file tool: the order
    // still has a pending tender, so the commission stays deferred.
    $this->postJson('/admin/api/v1/bank-reconciliation/commit', ['payment_ids' => [$ctx['payment_id']]])
        ->assertOk();
    expect(DB::table('pos_sale_commissions')->count())->toBe(0);

    // Approving the order settles the remaining half AND fires the split.
    $this->postJson('/admin/api/v1/pending-reconciliation/approve', ['order_ids' => [$ctx['order_id']]])
        ->assertOk();
    $this->assertDatabaseHas('pos_payments', ['id' => $secondId, 'status' => 'success']);
    expect(DB::table('pos_sale_commissions')->where('order_id', $ctx['order_id'])->count())->toBe(3);
});
