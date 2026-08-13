<?php

declare(strict_types=1);

use App\Enums\PlatformRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 0 exit — W-D1 (EXIT-11): an order.void that commits MID-FLIGHT,
 * after an admin settlement path selected its candidate but before any
 * tender flipped, must stay terminal. Both admin settle routes are raced
 * with the same deterministic eloquent.retrieved hook the retry-sweep
 * regression uses (RetryRoundupForwardingTest): the hook commits the void
 * (order + its riding donation) the instant the path hydrates its stale
 * candidate row.
 *
 * Contract (coverage matrix Part B, EXIT-11): zero commission rows minted,
 * zero charity HTTP, donation status never overwritten, the pending tender
 * NOT flipped to success (it stays queued as void_order_refund_review
 * evidence), and no approval audit written.
 *
 * ADJUDICATION NOTE (2026-08-13): the approval-path leg was reworked after
 * the integrated PG gate refuted its original assertion — SQLite's ignored
 * FOR UPDATE let this hook manufacture an interleaving PostgreSQL forbids.
 * The bank-file leg (candidates scanned unlocked BEFORE the settlement
 * transaction) races for real on both engines and keeps the full battery.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
    config(['services.charity.url' => 'http://charity.test']);
});

function midFlightVoidActingAs(TestCase $test): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole(PlatformRole::SuperAdmin->value);
    $test->actingAs($user);

    return $user;
}

/**
 * A paid order with one pending_reconciliation card tender, a commission
 * profile (so a missed void guard WOULD mint rows), and one unforwarded
 * round-up donation riding the tender.
 *
 * @return array{order_id: int, payment_id: int, donation_id: int, donation_uuid: string, device_id: int}
 */
function midFlightVoidSeedOrder(): array
{
    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $device = Device::factory()->assigned()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
    ]);

    $orderId = (int) DB::table('pos_orders')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'branch_id' => $branch->id,
        'order_type' => 'quick', 'status' => 'paid', 'source' => 'main_pos',
        'subtotal' => '5.000', 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => '5.000',
        'opened_at' => now(), 'closed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $paymentId = (int) DB::table('pos_payments')->insertGetId([
        'uuid' => (string) Str::uuid(), 'order_id' => $orderId, 'method' => 'card',
        'amount' => '5.000', 'status' => 'pending_reconciliation', 'pending_reconciliation' => true,
        'softpos_reference' => 'NFC-RACE-1', 'softpos_auth_code' => 'A11',
        'bank_response' => json_encode(['status' => 'timeout']),
        'device_id' => $device->id, 'terminal_id' => $device->terminal_id,
        'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $profileId = (int) DB::table('pos_commission_profiles')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id,
        'is_active' => true, 'merchant_percent' => 95,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ([['platform', 'Platform', 2, 0], ['bank', 'Acme Bank', 3, 1]] as [$type, $label, $percent, $sort]) {
        DB::table('pos_commission_shares')->insert([
            'commission_profile_id' => $profileId, 'party_type' => $type, 'label' => $label,
            'percent' => $percent, 'sort_order' => $sort, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $donationUuid = (string) Str::uuid();
    $donationId = (int) DB::table('pos_roundup_donations')->insertGetId([
        'uuid' => $donationUuid,
        'company_id' => $company->id, 'branch_id' => $branch->id,
        'device_id' => $device->id, 'order_id' => $orderId, 'payment_id' => $paymentId,
        'terminal_id' => $device->terminal_id,
        'amount' => '0.200', 'bank_response' => json_encode(['status' => 'timeout']),
        'bank_id' => $device->bank_id,
        'commission_profile_id' => $device->commission_profile_id,
        'organization_id' => $device->organization_id,
        'branch_name' => $branch->name,
        'country_id' => $branch->country_id,
        'region_id' => $branch->region_id,
        'district_id' => $branch->district_id,
        'city_id' => $branch->city_id,
        'latitude' => $branch->latitude,
        'longitude' => $branch->longitude,
        'status' => 'pending', 'source' => 'pos_roundup',
        'occurred_at' => now(), 'forwarded_at' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [
        'order_id' => $orderId,
        'payment_id' => $paymentId,
        'donation_id' => $donationId,
        'donation_uuid' => $donationUuid,
        'device_id' => (int) $device->id,
    ];
}

/** Commit the void (order + donation) exactly once, from inside the hook. */
function midFlightVoidCommit(array $ctx): void
{
    DB::transaction(function () use ($ctx): void {
        DB::table('pos_orders')
            ->where('id', $ctx['order_id'])
            ->update(['status' => 'void', 'updated_at' => now()]);
        DB::table('pos_roundup_donations')
            ->where('id', $ctx['donation_id'])
            ->update(['status' => 'void', 'updated_at' => now()]);
    });
}

/** The shared EXIT-11 terminality battery, asserted after either race. */
function midFlightVoidAssertTerminal(array $ctx): void
{
    // Zero commission rows minted for the voided sale.
    expect(DB::table('pos_sale_commissions')->where('order_id', $ctx['order_id'])->count())->toBe(0);

    // The tender is NOT flipped to success: a void order's charge stays in
    // the queue as non-actionable refund/exception evidence (ADM-001).
    $payment = DB::table('pos_payments')->find($ctx['payment_id']);
    expect((string) $payment->status)->toBe('pending_reconciliation');
    expect((bool) $payment->pending_reconciliation)->toBeTrue();

    // No approval decision audit for a decision that never happened.
    expect(DB::table('pos_audit_logs')
        ->where('event', 'order.reconciliation_approved')
        ->where('auditable_id', $ctx['order_id'])
        ->count())->toBe(0);
    expect(DB::table('pos_audit_logs')
        ->where('event', 'payment.reconciled')
        ->where('auditable_id', $ctx['payment_id'])
        ->count())->toBe(0);

    // The void donation is never overwritten and never forwarded.
    $donation = DB::table('pos_roundup_donations')->find($ctx['donation_id']);
    expect($donation->status)->toBe('void');
    expect($donation->forwarded_at)->toBeNull();
    Http::assertNothingSent();
}

it('a void committing after the approval decision point never leaks a charity forward or overwrites the donation', function (): void {
    Http::fake();
    midFlightVoidActingAs($this);
    $ctx = midFlightVoidSeedOrder();

    $eventName = 'eloquent.retrieved: '.Order::class;
    $becameVoid = false;

    // Commit the void the instant the approval path hydrates its order
    // candidate (same technique as the retry-sweep void-race regression).
    Event::listen($eventName, function (Order $candidate) use (&$becameVoid, $ctx): void {
        if ($becameVoid || (int) $candidate->id !== $ctx['order_id']) {
            return;
        }

        $becameVoid = true;
        midFlightVoidCommit($ctx);
    });

    try {
        $this->postJson('/admin/api/v1/pending-reconciliation/approve', ['order_ids' => [$ctx['order_id']]])
            ->assertOk();
    } finally {
        Event::forget($eventName);
    }

    expect($becameVoid)->toBeTrue();

    // ADJUDICATED 2026-08-13 (Phase 0 integrated gate, disposable-PG run
    // 'shakeb'; scenario exit11_void_vs_approval.ps1 in pos_machine
    // tool/phase0_exit/): on PostgreSQL this hook's interleaving is
    // UNREACHABLE. ApprovePendingReconciliationAction hydrates the order
    // under SELECT..FOR UPDATE inside its transaction and re-checks void on
    // the locked row; pos_api's VoidOrderHandler serializes on the same
    // pos_orders row lock. A real void therefore commits either BEFORE the
    // locked read (approval no-ops — the deterministic leg proves it) or
    // AFTER approval commits (the void unwind deletes unclaimed commissions
    // and voids the donation — second deterministic leg + a 10-order
    // concurrent barrage, terminal-void invariant held on all 12 orders).
    // SQLite ignores FOR UPDATE, so this in-process hook manufactures an
    // ordering the real engine forbids; the original assertion that zero
    // commissions exist at this point pinned that impossible interleaving
    // (FAIL-EXIT-11 refuted — see docs/phase0_failures in pos_machine).
    //
    // What this layer CAN pin — and what held even under the manufactured
    // interleave — is the post-decision forward guard: it re-reads the
    // donation fresh under its own lock, so a voided donation is never
    // forwarded, never overwritten, and no charity HTTP leaves the process.
    $donation = DB::table('pos_roundup_donations')->find($ctx['donation_id']);
    expect($donation->status)->toBe('void');
    expect($donation->forwarded_at)->toBeNull();
    Http::assertNothingSent();
});

it('bank-file commit racing a mid-flight void stays terminal: no effects, no flip, no audit', function (): void {
    Http::fake();
    midFlightVoidActingAs($this);
    $ctx = midFlightVoidSeedOrder();

    $eventName = 'eloquent.retrieved: '.Payment::class;
    $becameVoid = false;

    // The bank-file path scans its payment candidates WITHOUT locks before
    // its settlement transaction. Commit the void the instant the candidate
    // payment hydrates — after the list select, before the tender flip.
    Event::listen($eventName, function (Payment $candidate) use (&$becameVoid, $ctx): void {
        if ($becameVoid || (int) $candidate->id !== $ctx['payment_id']) {
            return;
        }

        $becameVoid = true;
        midFlightVoidCommit($ctx);
    });

    try {
        $response = $this->postJson('/admin/api/v1/bank-reconciliation/commit', ['payment_ids' => [$ctx['payment_id']]]);
    } finally {
        Event::forget($eventName);
    }

    $response->assertOk()->assertJsonPath('data.reconciled', 0);
    expect($becameVoid)->toBeTrue();
    midFlightVoidAssertTerminal($ctx);
});
