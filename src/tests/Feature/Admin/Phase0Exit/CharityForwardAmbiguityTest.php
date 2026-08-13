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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 0 exit — W-D3 (EXIT-12): forwarding-ambiguity outcomes after the
 * local settlement committed.
 *
 * (a) The charity request times out / the connection drops AFTER the local
 *     approve committed. The receiver may or may not have processed it —
 *     the ONLY safe local outcome is: settlement stays committed (never
 *     rolled back), forwarded_at stays NULL, and the hourly retry re-sends
 *     the SAME durable pos_reference uuid so the receiver's dedupe resolves
 *     the ambiguity to exactly one charity_transactions row.
 *
 * (b) Matrix flag F-5: the receiver answers HTTP 2xx whose body says
 *     success:false (an application-level refusal). That donation was NOT
 *     accepted — stamping forwarded_at would silently lose the customer's
 *     round-up forever, because the retry sweep only scans NULL markers.
 *     The donation must remain retriable.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
    config(['services.charity.url' => 'http://charity.test']);
});

function forwardAmbiguityActingAs(TestCase $test): User
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
 * profile, and one unforwarded round-up donation riding the tender.
 *
 * @return array{order_id: int, payment_id: int, donation_id: int, donation_uuid: string}
 */
function forwardAmbiguitySeedOrder(): array
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
        'softpos_reference' => 'NFC-AMB-1', 'softpos_auth_code' => 'A55',
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
    ];
}

it('a dropped connection never rolls back settlement and the retry re-sends the same uuid', function (): void {
    forwardAmbiguityActingAs($this);
    $ctx = forwardAmbiguitySeedOrder();

    // First charity attempt: the connection dies after the request left (the
    // receiver may already have committed). Every later attempt succeeds.
    $attempts = [];
    Http::fake(function (Request $request) use (&$attempts) {
        $attempts[] = [
            'pos_reference' => $request['pos_reference'] ?? null,
            'status' => $request['status'] ?? null,
        ];
        if (count($attempts) === 1) {
            throw new ConnectionException('cURL error 28: Connection timed out after 8001 milliseconds');
        }

        return Http::response(['success' => true], 201);
    });

    $this->postJson('/admin/api/v1/pending-reconciliation/approve', ['order_ids' => [$ctx['order_id']]])
        ->assertOk()
        ->assertJsonPath('data.orders_approved', 1)
        ->assertJsonPath('data.effects.commissions_recorded', 1)
        ->assertJsonPath('data.effects.donations_forwarded', 0)
        ->assertJsonPath('data.effects.donation_forward_failures.0.donation_id', $ctx['donation_id']);

    // The ambiguous external failure must NOT roll back the local settlement.
    $this->assertDatabaseHas('pos_payments', [
        'id' => $ctx['payment_id'], 'status' => 'success', 'pending_reconciliation' => false,
    ]);
    expect(DB::table('pos_sale_commissions')->where('order_id', $ctx['order_id'])->count())->toBe(3);

    // The donation stays durably settled AND retriable: marker NULL.
    $donation = DB::table('pos_roundup_donations')->find($ctx['donation_id']);
    expect($donation->status)->toBe('success');
    expect($donation->forwarded_at)->toBeNull();
    expect($attempts)->toHaveCount(1);
    expect($attempts[0]['pos_reference'])->toBe($ctx['donation_uuid']);

    // The hourly retry re-sends the SAME durable uuid — the receiver's
    // dedupe key — so a receiver that DID commit answers idempotently.
    DB::table('pos_roundup_donations')
        ->where('id', $ctx['donation_id'])
        ->update(['created_at' => now()->subMinutes(11)]);

    $this->artisan('donations:retry-roundup-forwarding')
        ->expectsOutputToContain('attempted=1 forwarded=1')
        ->assertSuccessful();

    $donation = DB::table('pos_roundup_donations')->find($ctx['donation_id']);
    expect($donation->status)->toBe('success');
    expect($donation->forwarded_at)->not->toBeNull();
    expect($attempts)->toHaveCount(2);
    expect($attempts[1]['pos_reference'])->toBe($ctx['donation_uuid']);
    expect($attempts[1]['status'])->toBe('success');

    // Local money effects never re-mint on retry.
    expect(DB::table('pos_sale_commissions')->where('order_id', $ctx['order_id'])->count())->toBe(3);
});

it('a 2xx receiver reply whose body says success:false is not stamped forwarded', function (): void {
    forwardAmbiguityActingAs($this);
    $ctx = forwardAmbiguitySeedOrder();

    // Application-level refusal: transport succeeded, donation NOT accepted.
    Http::fake(['*' => Http::response(['success' => false, 'message' => 'receiver validation failed'], 200)]);

    $response = $this->postJson('/admin/api/v1/pending-reconciliation/approve', ['order_ids' => [$ctx['order_id']]])
        ->assertOk();

    // The receiver refused the donation, so it must remain retriable: the
    // retry sweep scans forwarded_at IS NULL — stamping the marker here
    // would silently lose the customer's round-up forever.
    expect(DB::table('pos_roundup_donations')->find($ctx['donation_id'])->forwarded_at)->toBeNull();
    expect($response->json('data.effects.donations_forwarded'))->toBe(0);
    expect($response->json('data.effects.donation_forward_failures.0.donation_id'))->toBe($ctx['donation_id']);

    // Local settlement is still committed either way.
    $this->assertDatabaseHas('pos_payments', [
        'id' => $ctx['payment_id'], 'status' => 'success', 'pending_reconciliation' => false,
    ]);
    expect(DB::table('pos_sale_commissions')->where('order_id', $ctx['order_id'])->count())->toBe(3);
});
