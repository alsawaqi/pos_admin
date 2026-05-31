<?php

declare(strict_types=1);

use App\Models\RoundupDonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the pos_roundup_donations table with the expected columns', function (): void {
    expect(Schema::hasColumns('pos_roundup_donations', [
        'uuid',
        'company_id',
        'branch_id',
        'device_id',
        'order_id',
        'payment_id',
        'bank_id',
        'terminal_id',
        'commission_profile_id',
        'amount',
        'bank_response',
        'status',
        'source',
        'country_id',
        'region_id',
        'district_id',
        'city_id',
        'latitude',
        'longitude',
        'client_event_id',
        'occurred_at',
    ]))->toBeTrue();
});

it('persists a round-up donation and casts amount + bank_response', function (): void {
    $donation = new RoundupDonation();
    $donation->forceFill([
        'uuid' => 'roundup-uuid-1',
        'company_id' => 1,
        'branch_id' => 2,
        'device_id' => 3,
        'order_id' => 4,
        'payment_id' => 5,
        'bank_id' => 6,
        'terminal_id' => 'TID-001',
        'amount' => '0.2',
        'bank_response' => ['approvalCode' => 'XYZ', 'status' => 'success'],
        'status' => 'success',
    ])->save();

    $fresh = RoundupDonation::query()->firstOrFail();

    expect($fresh->amount)->toBe('0.200');
    expect($fresh->source)->toBe('pos_roundup');
    expect($fresh->bank_response)->toBe(['approvalCode' => 'XYZ', 'status' => 'success']);
    expect($fresh->bank_id)->toBe(6);
});
