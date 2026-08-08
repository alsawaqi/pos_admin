<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use App\Models\RoundupDonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Master-plan step 10 — donations:retry-roundup-forwarding, the hourly sweep
 * that re-forwards charity round-ups whose inline forward failed. Pending-
 * reconciliation rides stay with the approval flow; young rows get a grace
 * window; a still-failing forward leaves the marker NULL for the next run.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['services.charity.url' => 'https://charity.test']);
});

/**
 * A full parent graph (pos_payments FKs to pos_orders) + one unforwarded
 * round-up riding a card payment. Returns [donation id, donation uuid].
 *
 * @return array{donation_id: int, uuid: string, order_id: int, payment_id: int, device_id: int, branch_id: int, branch_name: string}
 */
function retrySweepSeedRoundup(
    bool $pendingRecon = false,
    ?Carbon $createdAt = null,
    string $status = 'success',
): array {
    $createdAt ??= now()->subMinutes(30);

    $company = Company::factory()->create();
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $device = Device::factory()->assigned()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
    ]);

    $orderId = DB::table('pos_orders')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'branch_id' => $branch->id,
        'order_type' => 'quick', 'status' => 'paid', 'source' => 'main_pos',
        'subtotal' => '1.000', 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => '1.000',
        'opened_at' => $createdAt, 'created_at' => $createdAt, 'updated_at' => $createdAt,
    ]);

    $paymentId = DB::table('pos_payments')->insertGetId([
        'uuid' => (string) Str::uuid(), 'order_id' => $orderId, 'method' => 'card',
        'amount' => '1.000', 'status' => $pendingRecon ? 'pending_reconciliation' : 'success',
        'pending_reconciliation' => $pendingRecon,
        'device_id' => $device->id, 'terminal_id' => $device->terminal_id,
        'captured_at' => $createdAt, 'created_at' => $createdAt, 'updated_at' => $createdAt,
    ]);

    $uuid = (string) Str::uuid();
    $donationId = DB::table('pos_roundup_donations')->insertGetId([
        'uuid' => $uuid,
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'device_id' => $device->id,
        'order_id' => $orderId,
        'payment_id' => $paymentId,
        'bank_id' => $device->bank_id,
        'terminal_id' => $device->terminal_id,
        'commission_profile_id' => $device->commission_profile_id,
        'organization_id' => $device->organization_id,
        'branch_name' => $branch->name,
        'country_id' => $branch->country_id,
        'region_id' => $branch->region_id,
        'district_id' => $branch->district_id,
        'city_id' => $branch->city_id,
        'latitude' => $branch->latitude,
        'longitude' => $branch->longitude,
        'amount' => '0.200',
        'status' => $status,
        'source' => 'pos_roundup',
        'occurred_at' => $createdAt,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    return [
        'donation_id' => $donationId,
        'uuid' => $uuid,
        'order_id' => $orderId,
        'payment_id' => $paymentId,
        'device_id' => (int) $device->id,
        'branch_id' => (int) $branch->id,
        'branch_name' => (string) $branch->name,
    ];
}

function retrySweepForwardedAt(int $donationId): ?string
{
    return DB::table('pos_roundup_donations')->where('id', $donationId)->value('forwarded_at');
}

it('re-forwards a receipt-less successful round-up with its durable status and idempotency key', function (): void {
    Http::fake(['charity.test/*' => Http::response(['success' => true], 201)]);
    $seed = retrySweepSeedRoundup();

    $this->artisan('donations:retry-roundup-forwarding')
        ->expectsOutputToContain('forwarded=1')
        ->assertSuccessful();

    expect(retrySweepForwardedAt($seed['donation_id']))->not->toBeNull();

    // The UUID is the charity-side dedupe key. The durable local status must
    // also ride explicitly because this valid success has no bank receipt.
    Http::assertSent(function ($request) use ($seed) {
        return str_contains($request->url(), '/api/donations-pos-roundup')
            && $request['pos_reference'] === $seed['uuid']
            && $request['status'] === 'success'
            && $request['amount'] === '0.200';
    });
});

it('never forwards rejected or void round-ups', function (): void {
    Http::fake();
    $rejected = retrySweepSeedRoundup(status: 'rejected');
    $void = retrySweepSeedRoundup(status: 'void');

    $this->artisan('donations:retry-roundup-forwarding')
        ->expectsOutputToContain('Nothing to retry')
        ->assertSuccessful();

    expect(retrySweepForwardedAt($rejected['donation_id']))->toBeNull()
        ->and(retrySweepForwardedAt($void['donation_id']))->toBeNull();
    Http::assertNothingSent();
});

it('revalidates a selected donation under the order lock before forwarding', function (): void {
    Http::fake(['charity.test/*' => Http::response(['success' => true], 201)]);
    $seed = retrySweepSeedRoundup();
    $eventName = 'eloquent.retrieved: '.RoundupDonation::class;
    $becameVoid = false;

    // Deterministically change the committed row immediately after the outer
    // success query selected and hydrated its stale candidate. A safe retry
    // must lock the order, reload the donation, and stop before HTTP.
    Event::listen($eventName, function (RoundupDonation $candidate) use (&$becameVoid, $seed): void {
        if ($becameVoid || (int) $candidate->id !== $seed['donation_id']) {
            return;
        }

        $becameVoid = true;
        DB::transaction(function () use ($seed): void {
            DB::table('pos_orders')
                ->where('id', $seed['order_id'])
                ->update(['status' => 'void', 'updated_at' => now()]);
            DB::table('pos_roundup_donations')
                ->where('id', $seed['donation_id'])
                ->update(['status' => 'void', 'updated_at' => now()]);
        });
    });

    try {
        $this->artisan('donations:retry-roundup-forwarding')
            ->expectsOutputToContain('attempted=0 forwarded=0')
            ->assertSuccessful();
    } finally {
        Event::forget($eventName);
    }

    expect($becameVoid)->toBeTrue()
        ->and(DB::table('pos_orders')->where('id', $seed['order_id'])->value('status'))->toBe('void')
        ->and(DB::table('pos_roundup_donations')->where('id', $seed['donation_id'])->value('status'))->toBe('void')
        ->and(retrySweepForwardedAt($seed['donation_id']))->toBeNull();
    Http::assertNothingSent();
});

it('leaves pending-reconciliation round-ups to the admin approval flow', function (): void {
    Http::fake();
    $seed = retrySweepSeedRoundup(pendingRecon: true);

    $this->artisan('donations:retry-roundup-forwarding')
        ->expectsOutputToContain('deferred-to-approval=1')
        ->assertSuccessful();

    expect(retrySweepForwardedAt($seed['donation_id']))->toBeNull();
    Http::assertNothingSent();
});
it('defers a pending round-up until every split tender settles', function (): void {
    Http::fake(['charity.test/*' => Http::response(['success' => true], 201)]);
    $seed = retrySweepSeedRoundup(status: 'pending');

    DB::table('pos_payments')
        ->where('id', $seed['payment_id'])
        ->update(['amount' => '0.500', 'updated_at' => now()]);
    $secondPaymentId = (int) DB::table('pos_payments')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'order_id' => $seed['order_id'],
        'method' => 'card',
        'amount' => '0.500',
        'status' => 'pending_reconciliation',
        'pending_reconciliation' => true,
        'device_id' => $seed['device_id'],
        'terminal_id' => null,
        'captured_at' => now()->subMinutes(30),
        'created_at' => now()->subMinutes(30),
        'updated_at' => now()->subMinutes(30),
    ]);

    $this->artisan('donations:retry-roundup-forwarding')
        ->expectsOutputToContain('attempted=0 forwarded=0 deferred-to-approval=1')
        ->assertSuccessful();

    expect(retrySweepForwardedAt($seed['donation_id']))->toBeNull();
    Http::assertNothingSent();

    DB::table('pos_payments')
        ->where('id', $secondPaymentId)
        ->update([
            'status' => 'success',
            'pending_reconciliation' => false,
            'updated_at' => now(),
        ]);

    $this->artisan('donations:retry-roundup-forwarding')
        ->expectsOutputToContain('attempted=1 forwarded=1')
        ->assertSuccessful();

    expect(retrySweepForwardedAt($seed['donation_id']))->not->toBeNull()
        ->and(DB::table('pos_roundup_donations')->where('id', $seed['donation_id'])->value('status'))->toBe('success');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request['pos_reference'] === $seed['uuid']);
});

it('scans past an older deferred row when the attempt limit is one', function (): void {
    Http::fake(['charity.test/*' => Http::response(['success' => true], 201)]);
    $deferred = retrySweepSeedRoundup(pendingRecon: true, createdAt: now()->subHours(2));
    $eligible = retrySweepSeedRoundup(createdAt: now()->subHour());

    $this->artisan('donations:retry-roundup-forwarding', ['--limit' => 1])
        ->expectsOutputToContain('attempted=1 forwarded=1 deferred-to-approval=1')
        ->assertSuccessful();

    expect(retrySweepForwardedAt($deferred['donation_id']))->toBeNull()
        ->and(retrySweepForwardedAt($eligible['donation_id']))->not->toBeNull();
    Http::assertSentCount(1);
    Http::assertSent(
        fn ($request): bool => $request['pos_reference'] === $eligible['uuid'],
    );
});

it('scans past a missing dependency and logs warnings plus a structured summary', function (): void {
    Http::fake(['charity.test/*' => Http::response(['success' => true], 201)]);
    Log::spy();
    $missing = retrySweepSeedRoundup();
    DB::table('pos_roundup_donations')
        ->where('id', $missing['donation_id'])
        ->update(['payment_id' => 999999999]);
    $eligible = retrySweepSeedRoundup();

    $this->artisan('donations:retry-roundup-forwarding', ['--limit' => 1])
        ->expectsOutputToContain('attempted=1 forwarded=1 deferred-to-approval=0 missing-payment=1')
        ->assertSuccessful();

    expect(retrySweepForwardedAt($missing['donation_id']))->toBeNull()
        ->and(retrySweepForwardedAt($eligible['donation_id']))->not->toBeNull();
    Http::assertSentCount(1);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Charity roundup retry candidate skipped'
            && $context['donation_id'] === $missing['donation_id']
            && $context['reason'] === 'missing_payment');
    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Charity roundup retry sweep completed'
            && $context['scanned'] === 2
            && $context['attempted'] === 1
            && $context['forwarded'] === 1
            && $context['missing_payments'] === 1);
});

it('logs a structured zero-work summary for scheduled observability', function (): void {
    Http::fake();
    Log::spy();

    $this->artisan('donations:retry-roundup-forwarding')
        ->expectsOutputToContain('Nothing to retry')
        ->assertSuccessful();

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Charity roundup retry sweep completed'
            && $context === [
                'scanned' => 0,
                'attempted' => 0,
                'forwarded' => 0,
                'deferred' => 0,
                'missing_payments' => 0,
                'missing_devices' => 0,
                'missing_branches' => 0,
                'failed' => 0,
            ]);
    Http::assertNothingSent();
});

it('forwards with soft-deleted device and branch origin snapshots', function (): void {
    Http::fake(['charity.test/*' => Http::response(['success' => true], 201)]);
    $seed = retrySweepSeedRoundup();

    Device::query()->findOrFail($seed['device_id'])->delete();
    Branch::query()->findOrFail($seed['branch_id'])->delete();

    $this->artisan('donations:retry-roundup-forwarding', ['--limit' => 1])
        ->expectsOutputToContain('forwarded=1')
        ->assertSuccessful();

    expect(retrySweepForwardedAt($seed['donation_id']))->not->toBeNull();
    Http::assertSent(function ($request) use ($seed): bool {
        return $request['pos_device_id'] === $seed['device_id']
            && $request['pos_branch_id'] === $seed['branch_id']
            && $request['pos_branch_name'] === $seed['branch_name'];
    });
});

it('gives the inline forward a 10-minute grace window before retrying', function (): void {
    Http::fake();
    $seed = retrySweepSeedRoundup(createdAt: now()->subMinutes(2));

    $this->artisan('donations:retry-roundup-forwarding')
        ->expectsOutputToContain('Nothing to retry')
        ->assertSuccessful();

    expect(retrySweepForwardedAt($seed['donation_id']))->toBeNull();
    Http::assertNothingSent();
});

it('keeps the marker NULL for the next run when the charity app still fails', function (): void {
    Http::fake(['charity.test/*' => Http::response('down', 500)]);
    $seed = retrySweepSeedRoundup();

    $this->artisan('donations:retry-roundup-forwarding')
        ->expectsOutputToContain('still-failing=1')
        ->assertSuccessful();

    expect(retrySweepForwardedAt($seed['donation_id']))->toBeNull();
});
