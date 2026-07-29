<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
 * @return array{donation_id: int, uuid: string}
 */
function retrySweepSeedRoundup(bool $pendingRecon = false, ?Carbon $createdAt = null): array
{
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
        'amount' => '0.200',
        'status' => 'success',
        'source' => 'pos_roundup',
        'occurred_at' => $createdAt,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    return ['donation_id' => $donationId, 'uuid' => $uuid];
}

function retrySweepForwardedAt(int $donationId): ?string
{
    return DB::table('pos_roundup_donations')->where('id', $donationId)->value('forwarded_at');
}

it('re-forwards an eligible round-up with its uuid as pos_reference and stamps forwarded_at', function (): void {
    Http::fake(['charity.test/*' => Http::response(['success' => true], 201)]);
    $seed = retrySweepSeedRoundup();

    $this->artisan('donations:retry-roundup-forwarding')
        ->expectsOutputToContain('forwarded=1')
        ->assertSuccessful();

    expect(retrySweepForwardedAt($seed['donation_id']))->not->toBeNull();

    // The uuid rides as pos_reference (the charity-side dedupe key) and the
    // status is null — a RETRY of the inline attempt, not the approval
    // path's confirmed-'success' override.
    Http::assertSent(function ($request) use ($seed) {
        return str_contains($request->url(), '/api/donations-pos-roundup')
            && $request['pos_reference'] === $seed['uuid']
            && $request['status'] === null
            && $request['amount'] === '0.200';
    });
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
