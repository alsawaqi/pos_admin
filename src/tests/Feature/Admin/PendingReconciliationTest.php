<?php

declare(strict_types=1);

use App\Actions\Admin\ReconcilePaymentsAction;
use App\Actions\Admin\Reconciliation\ApprovePendingReconciliationAction;
use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

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

function pendingReconActingAs(TestCase $test, string $role): User
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
        'bank_id' => $ctx['device']->bank_id,
        'commission_profile_id' => $ctx['device']->commission_profile_id,
        'organization_id' => $ctx['device']->organization_id,
        'branch_name' => $ctx['branch']->name,
        'country_id' => $ctx['branch']->country_id,
        'region_id' => $ctx['branch']->region_id,
        'district_id' => $ctx['branch']->district_id,
        'city_id' => $ctx['branch']->city_id,
        'latitude' => $ctx['branch']->latitude,
        'longitude' => $ctx['branch']->longitude,
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

it('keeps a void charge visible as a non-actionable refund exception', function (): void {
    pendingReconActingAs($this, PlatformRole::SuperAdmin->value);
    $ctx = pendingReconSeedOrder(orderAttrs: ['status' => 'void']);

    $res = $this->getJson('/admin/api/v1/pending-reconciliation')
        ->assertOk()
        ->assertJsonPath('totals.orders', 1);

    $row = collect($res->json('data'))->firstWhere('id', $ctx['order_id']);
    expect($row)->not->toBeNull()
        ->and($row['status'])->toBe('void')
        ->and($row['reconciliation_actionable'])->toBeFalse()
        ->and($row['exception_code'])->toBe('void_order_refund_review');

    // The charge stays pending as evidence: the queue must direct an operator
    // to refund/exception review rather than silently hiding a possibly
    // captured payment or presenting approve/reject as valid decisions.
    $this->assertDatabaseHas('pos_payments', [
        'id' => $ctx['payment_id'],
        'pending_reconciliation' => true,
    ]);
});

it('keeps void rows out of every reconciliation action in the admin queue UI', function (): void {
    $source = file_get_contents(resource_path('js/Pages/Admin/PendingReconciliation/Index.vue'));

    expect($source)
        ->toContain('return row.reconciliation_actionable === true;')
        ->toContain('if (!isActionable(row)) return;')
        ->toContain('const actionableRows = computed(() => rows.value.filter(isActionable));')
        ->toContain('const actionableIds = orderIds.filter((id) => allowed.has(id));')
        ->toContain('if (target === null || !isActionable(target) || rejecting.value)')
        ->toContain(':disabled="!isActionable(row)"');

    // Both row-level decisions live under the same actionable-only branch;
    // the v-else branch renders refund/exception guidance instead.
    $guardedActions = Str::between(
        $source,
        '<div v-if="isActionable(row)"',
        '<div v-else',
    );
    expect($guardedActions)
        ->toContain('@click="void approveOrders([row.id])"')
        ->toContain('@click="rejectTarget = row"');
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
    $decision = AuditLog::query()
        ->where('event', 'order.reconciliation_approved')
        ->where('auditable_id', $ctx['order_id'])
        ->sole();
    expect($decision->new_values['roundup_donations_queued'])->toBe([$donationId]);
    expect($decision->new_values['roundup_donations_forwarded'])->toBe([]);

    // Replaying the approval (double-click / retry) splits NOTHING twice.
    $res = $this->postJson('/admin/api/v1/pending-reconciliation/approve', ['order_ids' => [$ctx['order_id']]])
        ->assertOk();
    expect($res->json('data.orders_approved'))->toBe(0);
    expect($res->json('data.payments_reconciled'))->toBe(0);
    expect($res->json('data.effects.commissions_recorded'))->toBe(0);
    expect(DB::table('pos_sale_commissions')->where('order_id', $ctx['order_id'])->count())->toBe(3);
    expect(DB::table('pos_roundup_donations')->where('order_id', $ctx['order_id'])->whereNull('forwarded_at')->count())->toBe(0);

    // A competing/replayed rejection also converges to a no-op after approval.
    $this->postJson('/admin/api/v1/pending-reconciliation/reject', ['order_ids' => [$ctx['order_id']]])
        ->assertOk()
        ->assertJsonPath('data.orders_rejected', 0)
        ->assertJsonPath('data.payments_failed', 0);
    $this->assertDatabaseHas('pos_payments', ['id' => $ctx['payment_id'], 'status' => 'success']);
    expect(DB::table('pos_roundup_donations')->find($donationId)->status)->toBe('success');
    Http::assertSentCount(1);
});

it('approve settles locally when charity fails and the hourly retry later forwards it', function (): void {
    config(['services.charity.url' => 'http://charity.test']);
    Http::fake([
        '*' => Http::sequence()
            ->push(['success' => false, 'message' => 'down'], 500)
            ->push(['success' => true], 201),
    ]);

    pendingReconActingAs($this, PlatformRole::SuperAdmin->value);
    $ctx = pendingReconSeedOrder();
    pendingReconSeedProfile($ctx['company']->id);
    $donationId = pendingReconSeedDonation($ctx);
    $donationUuid = (string) DB::table('pos_roundup_donations')->where('id', $donationId)->value('uuid');

    $res = $this->postJson('/admin/api/v1/pending-reconciliation/approve', ['order_ids' => [$ctx['order_id']]])
        ->assertOk();

    // The local approval sticks and durably marks the donation settled before
    // the failed external request; forwarded_at keeps it sweep-eligible.
    $this->assertDatabaseHas('pos_payments', [
        'id' => $ctx['payment_id'],
        'status' => 'success',
        'pending_reconciliation' => false,
    ]);
    expect($res->json('data.effects.commissions_recorded'))->toBe(1);
    expect($res->json('data.effects.donations_forwarded'))->toBe(0);
    expect($res->json('data.effects.donation_forward_failures.0.donation_id'))->toBe($donationId);
    $donation = DB::table('pos_roundup_donations')->find($donationId);
    expect($donation->status)->toBe('success');
    expect($donation->forwarded_at)->toBeNull();

    DB::table('pos_roundup_donations')
        ->where('id', $donationId)
        ->update(['created_at' => now()->subMinutes(11)]);

    $this->artisan('donations:retry-roundup-forwarding')
        ->expectsOutputToContain('attempted=1 forwarded=1')
        ->assertSuccessful();

    $donation = DB::table('pos_roundup_donations')->find($donationId);
    expect($donation->status)->toBe('success');
    expect($donation->forwarded_at)->not->toBeNull();
    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => $request['pos_reference'] === $donationUuid
        && $request['status'] === 'success');
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
    $donation = DB::table('pos_roundup_donations')->find($donationId);
    expect($donation->status)->toBe('rejected');
    expect($donation->forwarded_at)->toBeNull();

    $this->assertDatabaseHas('pos_audit_logs', ['event' => 'payment.reconciliation_rejected', 'auditable_id' => $ctx['payment_id']]);

    // And it left the queue.
    $res = $this->getJson('/admin/api/v1/pending-reconciliation')->assertOk();
    expect(collect($res->json('data'))->pluck('id')->all())->not->toContain($ctx['order_id']);
});

it('reject is terminal for a stale queue approval and fires no deferred effects', function (): void {
    config(['services.charity.url' => 'http://charity.test']);
    Http::fake(['*' => Http::response(['success' => true], 201)]);

    pendingReconActingAs($this, PlatformRole::SuperAdmin->value);
    $ctx = pendingReconSeedOrder();
    pendingReconSeedProfile($ctx['company']->id);
    $donationId = pendingReconSeedDonation($ctx);

    $this->postJson('/admin/api/v1/pending-reconciliation/reject', ['order_ids' => [$ctx['order_id']]])
        ->assertOk()
        ->assertJsonPath('data.orders_rejected', 1)
        ->assertJsonPath('data.payments_failed', 1);

    $this->postJson('/admin/api/v1/pending-reconciliation/approve', ['order_ids' => [$ctx['order_id']]])
        ->assertOk()
        ->assertJsonPath('data.orders_approved', 0)
        ->assertJsonPath('data.payments_reconciled', 0)
        ->assertJsonPath('data.effects.orders_settled', [])
        ->assertJsonPath('data.effects.commissions_recorded', 0)
        ->assertJsonPath('data.effects.donations_forwarded', 0);

    $this->assertDatabaseHas('pos_payments', [
        'id' => $ctx['payment_id'],
        'status' => 'failed',
        'pending_reconciliation' => false,
    ]);
    expect(DB::table('pos_roundup_donations')->find($donationId)->status)->toBe('rejected');
    expect(DB::table('pos_sale_commissions')->count())->toBe(0);
    expect(DB::table('pos_audit_logs')
        ->where('event', 'order.reconciliation_approved')
        ->where('auditable_id', $ctx['order_id'])
        ->count())->toBe(0);
    Http::assertNothingSent();
});

it('reject preserves void donations and only rejects donations on pending tenders', function (): void {
    config(['services.charity.url' => 'http://charity.test']);
    Http::fake();

    pendingReconActingAs($this, PlatformRole::SuperAdmin->value);
    $ctx = pendingReconSeedOrder();
    $rejectedDonationId = pendingReconSeedDonation($ctx);

    $voidDonationId = pendingReconSeedDonation($ctx, '0.100');
    DB::table('pos_roundup_donations')->where('id', $voidDonationId)->update(['status' => 'void']);

    $settledPaymentId = (int) DB::table('pos_payments')->insertGetId([
        'uuid' => (string) Str::uuid(), 'order_id' => $ctx['order_id'], 'method' => 'card',
        'amount' => '1.000', 'status' => 'success', 'pending_reconciliation' => false,
        'device_id' => $ctx['device']->id, 'terminal_id' => $ctx['device']->terminal_id,
        'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $settledDonationId = pendingReconSeedDonation([
        ...$ctx,
        'payment_id' => $settledPaymentId,
    ], '0.050');

    $this->postJson('/admin/api/v1/pending-reconciliation/reject', ['order_ids' => [$ctx['order_id']]])
        ->assertOk()
        ->assertJsonPath('data.orders_rejected', 1)
        ->assertJsonPath('data.payments_failed', 1);

    expect(DB::table('pos_roundup_donations')->find($rejectedDonationId)->status)->toBe('rejected');
    expect(DB::table('pos_roundup_donations')->find($voidDonationId)->status)->toBe('void');
    expect(DB::table('pos_roundup_donations')->find($settledDonationId)->status)->toBe('pending');
    Http::assertNothingSent();

    // A retry is idempotent: no tender remains pending and no donation changes.
    $this->postJson('/admin/api/v1/pending-reconciliation/reject', ['order_ids' => [$ctx['order_id']]])
        ->assertOk()
        ->assertJsonPath('data.orders_rejected', 0)
        ->assertJsonPath('data.payments_failed', 0);
    expect(DB::table('pos_roundup_donations')->find($rejectedDonationId)->status)->toBe('rejected');
    expect(DB::table('pos_roundup_donations')->find($voidDonationId)->status)->toBe('void');
    expect(DB::table('pos_roundup_donations')->find($settledDonationId)->status)->toBe('pending');
    Http::assertNothingSent();
});

// ─── Bank-file convergence ───────────────────────────────────────────────

it('keeps a bank-file batch atomic when deferred local audit fails', function (): void {
    config(['services.charity.url' => 'http://charity.test']);
    Http::fake(['*' => Http::response(['success' => true], 201)]);

    $admin = pendingReconActingAs($this, PlatformRole::SuperAdmin->value);
    $ctx = pendingReconSeedOrder();
    pendingReconSeedProfile($ctx['company']->id);
    $donationId = pendingReconSeedDonation($ctx);

    AuditLog::creating(function (AuditLog $audit): void {
        if ($audit->event === 'order.reconciliation_approved') {
            throw new RuntimeException('simulated bank deferred audit failure');
        }
    });

    expect(fn () => app(ReconcilePaymentsAction::class)->handle(
        [$ctx['payment_id']],
        $admin,
    ))->toThrow(RuntimeException::class, 'simulated bank deferred audit failure');

    $this->assertDatabaseHas('pos_payments', [
        'id' => $ctx['payment_id'],
        'status' => 'pending_reconciliation',
        'pending_reconciliation' => true,
    ]);
    expect(DB::table('pos_sale_commissions')->where('order_id', $ctx['order_id'])->count())->toBe(0);
    expect(DB::table('pos_audit_logs')->count())->toBe(0);
    $donation = DB::table('pos_roundup_donations')->find($donationId);
    expect($donation->status)->toBe('pending');
    expect($donation->forwarded_at)->toBeNull();
    Http::assertNothingSent();
});

it('commits the bank settlement before a best-effort charity outage', function (): void {
    config(['services.charity.url' => 'http://charity.test']);
    Http::fake(['*' => Http::response(['success' => false], 500)]);

    pendingReconActingAs($this, PlatformRole::SuperAdmin->value);
    $ctx = pendingReconSeedOrder();
    pendingReconSeedProfile($ctx['company']->id);
    $donationId = pendingReconSeedDonation($ctx);

    $this->postJson('/admin/api/v1/bank-reconciliation/commit', ['payment_ids' => [$ctx['payment_id']]])
        ->assertOk()
        ->assertJsonPath('data.reconciled', 1)
        ->assertJsonPath('data.effects.commissions_recorded', 1)
        ->assertJsonPath('data.effects.donations_forwarded', 0)
        ->assertJsonPath('data.effects.donation_forward_failures.0.donation_id', $donationId);

    $this->assertDatabaseHas('pos_payments', [
        'id' => $ctx['payment_id'],
        'status' => 'success',
        'pending_reconciliation' => false,
    ]);
    expect(DB::table('pos_sale_commissions')->where('order_id', $ctx['order_id'])->count())->toBe(3);

    $donation = DB::table('pos_roundup_donations')->find($donationId);
    expect($donation->status)->toBe('success');
    expect($donation->forwarded_at)->toBeNull();

    $decision = AuditLog::query()
        ->where('event', 'order.reconciliation_approved')
        ->where('auditable_id', $ctx['order_id'])
        ->sole();
    expect($decision->new_values['roundup_donations_queued'])->toBe([$donationId]);
    expect($decision->new_values['roundup_donations_forwarded'])->toBe([]);
    Http::assertSentCount(1);
});
it('bank-file deferred effects do not resurrect rejected or void donations', function (): void {
    config(['services.charity.url' => 'http://charity.test']);
    Http::fake(['*' => Http::response(['success' => true], 201)]);

    pendingReconActingAs($this, PlatformRole::SuperAdmin->value);
    $ctx = pendingReconSeedOrder();
    $rejectedDonationId = pendingReconSeedDonation($ctx);
    $voidDonationId = pendingReconSeedDonation($ctx, '0.100');
    DB::table('pos_roundup_donations')->where('id', $voidDonationId)->update(['status' => 'void']);

    $this->postJson('/admin/api/v1/pending-reconciliation/reject', ['order_ids' => [$ctx['order_id']]])
        ->assertOk()
        ->assertJsonPath('data.orders_rejected', 1);

    $this->postJson('/admin/api/v1/bank-reconciliation/commit', ['payment_ids' => [$ctx['payment_id']]])
        ->assertOk()
        ->assertJsonPath('data.reconciled', 1)
        ->assertJsonPath('data.effects.donations_forwarded', 0)
        ->assertJsonPath('data.effects.donation_forward_failures', []);

    expect(DB::table('pos_roundup_donations')->find($rejectedDonationId)->status)->toBe('rejected');
    expect(DB::table('pos_roundup_donations')->find($rejectedDonationId)->forwarded_at)->toBeNull();
    expect(DB::table('pos_roundup_donations')->find($voidDonationId)->status)->toBe('void');
    expect(DB::table('pos_roundup_donations')->find($voidDonationId)->forwarded_at)->toBeNull();
    Http::assertNothingSent();
});

it('the bank-file commit fires the same deferred effects', function (): void {
    config(['services.charity.url' => 'http://charity.test']);

    pendingReconActingAs($this, PlatformRole::SuperAdmin->value);
    $ctx = pendingReconSeedOrder();
    pendingReconSeedProfile($ctx['company']->id);
    $donationId = pendingReconSeedDonation($ctx);

    $localBatchCommitted = false;
    $sawCommitBeforeHttp = false;
    Event::listen(TransactionCommitted::class, function () use (&$localBatchCommitted): void {
        $localBatchCommitted = true;
    });
    Http::fake(function () use (&$localBatchCommitted, &$sawCommitBeforeHttp) {
        $sawCommitBeforeHttp = $localBatchCommitted;

        return Http::response(['success' => true], 201);
    });
    try {
        $response = $this->postJson('/admin/api/v1/bank-reconciliation/commit', [
            'payment_ids' => [$ctx['payment_id']],
        ]);
    } finally {
        Event::forget(TransactionCommitted::class);
    }
    $response->assertOk()->assertJsonPath('data.reconciled', 1);
    expect($sawCommitBeforeHttp)->toBeTrue();

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
it('rolls back payment flips and every local effect when the approval audit cannot commit', function (): void {
    config(['services.charity.url' => 'http://charity.test']);
    Http::fake(['*' => Http::response(['success' => true], 201)]);

    $admin = pendingReconActingAs($this, PlatformRole::SuperAdmin->value);
    $ctx = pendingReconSeedOrder();
    pendingReconSeedProfile($ctx['company']->id);
    $donationId = pendingReconSeedDonation($ctx);

    // Fail at the last local write, after payment audit, commission creation,
    // and donation queueing. Every database effect must roll back together,
    // and no external request may start before that commit succeeds.
    AuditLog::creating(function (AuditLog $audit): void {
        if ($audit->event === 'order.reconciliation_approved') {
            throw new RuntimeException('simulated approval audit failure');
        }
    });

    expect(fn () => app(ApprovePendingReconciliationAction::class)->handle(
        [$ctx['order_id']],
        $admin,
    ))->toThrow(RuntimeException::class, 'simulated approval audit failure');

    $this->assertDatabaseHas('pos_payments', [
        'id' => $ctx['payment_id'],
        'status' => 'pending_reconciliation',
        'pending_reconciliation' => true,
    ]);
    expect(DB::table('pos_sale_commissions')->where('order_id', $ctx['order_id'])->count())->toBe(0);
    $donation = DB::table('pos_roundup_donations')->find($donationId);
    expect($donation->status)->toBe('pending');
    expect($donation->forwarded_at)->toBeNull();
    expect(DB::table('pos_audit_logs')->count())->toBe(0);
    Http::assertNothingSent();
});

it('treats a void order as terminal and never approves, rejects, or forwards it', function (): void {
    config(['services.charity.url' => 'http://charity.test']);
    Http::fake(['*' => Http::response(['success' => true], 201)]);

    pendingReconActingAs($this, PlatformRole::SuperAdmin->value);
    $ctx = pendingReconSeedOrder();
    pendingReconSeedProfile($ctx['company']->id);
    $voidDonationId = pendingReconSeedDonation($ctx);
    $rejectedDonationId = pendingReconSeedDonation($ctx, '0.100');
    DB::table('pos_orders')->where('id', $ctx['order_id'])->update([
        'status' => 'void',
        'updated_at' => now(),
    ]);
    DB::table('pos_roundup_donations')->where('id', $voidDonationId)->update([
        'status' => 'void',
        'updated_at' => now(),
    ]);
    DB::table('pos_roundup_donations')->where('id', $rejectedDonationId)->update([
        'status' => 'rejected',
        'updated_at' => now(),
    ]);

    $this->postJson('/admin/api/v1/pending-reconciliation/approve', ['order_ids' => [$ctx['order_id']]])
        ->assertOk()
        ->assertJsonPath('data.orders_approved', 0)
        ->assertJsonPath('data.payments_reconciled', 0)
        ->assertJsonPath('data.effects.orders_settled', [])
        ->assertJsonPath('data.effects.commissions_recorded', 0)
        ->assertJsonPath('data.effects.donations_forwarded', 0);

    $this->postJson('/admin/api/v1/pending-reconciliation/reject', ['order_ids' => [$ctx['order_id']]])
        ->assertOk()
        ->assertJsonPath('data.orders_rejected', 0)
        ->assertJsonPath('data.payments_failed', 0);

    $this->assertDatabaseHas('pos_payments', [
        'id' => $ctx['payment_id'],
        'status' => 'pending_reconciliation',
        'pending_reconciliation' => true,
    ]);
    expect(DB::table('pos_roundup_donations')->find($voidDonationId)->status)->toBe('void');
    expect(DB::table('pos_roundup_donations')->find($rejectedDonationId)->status)->toBe('rejected');
    expect(DB::table('pos_sale_commissions')->count())->toBe(0);
    expect(DB::table('pos_audit_logs')->count())->toBe(0);
    Http::assertNothingSent();
});

it('approves and forwards with soft-deleted sale-time origins', function (): void {
    config(['services.charity.url' => 'http://charity.test']);
    Http::fake(['*' => Http::response(['success' => true], 201)]);

    pendingReconActingAs($this, PlatformRole::SuperAdmin->value);
    $ctx = pendingReconSeedOrder();
    pendingReconSeedProfile($ctx['company']->id);
    $donationId = pendingReconSeedDonation($ctx);
    $snapshot = DB::table('pos_roundup_donations')->find($donationId);

    $ctx['device']->delete();
    $ctx['branch']->delete();

    $this->postJson('/admin/api/v1/pending-reconciliation/approve', ['order_ids' => [$ctx['order_id']]])
        ->assertOk()
        ->assertJsonPath('data.orders_approved', 1)
        ->assertJsonPath('data.effects.commissions_recorded', 1)
        ->assertJsonPath('data.effects.donations_forwarded', 1);

    expect(DB::table('pos_sale_commissions')->where('order_id', $ctx['order_id'])->count())->toBe(3);
    $donation = DB::table('pos_roundup_donations')->find($donationId);
    expect($donation->status)->toBe('success');
    expect($donation->forwarded_at)->not->toBeNull();
    Http::assertSent(fn ($request): bool => $request['pos_device_id'] === $snapshot->device_id
        && $request['pos_branch_id'] === $snapshot->branch_id
        && $request['pos_branch_name'] === $snapshot->branch_name
        && $request['organization_id'] === $snapshot->organization_id);
});
