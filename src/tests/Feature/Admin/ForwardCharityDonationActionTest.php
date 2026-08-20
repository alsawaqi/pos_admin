<?php

declare(strict_types=1);

use App\Actions\Admin\Reconciliation\ForwardCharityDonationAction;
use App\Models\RoundupDonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('forwards a stored donation snapshot without rereading mutable origins', function (): void {
    config([
        'services.charity.url' => 'https://charity.test',
        'services.charity.timeout' => 8,
        'services.charity.roundup_hmac_secret' => 'roundup-test-secret',
    ]);
    Http::fake([
        'https://charity.test/*' => Http::response(['success' => true], 201),
    ]);

    $donation = new RoundupDonation;
    $donation->forceFill([
        'uuid' => '00000000-0000-4000-8000-000000000001',
        'company_id' => 10,
        'branch_id' => 20,
        'branch_name' => 'Sale-time Branch',
        'device_id' => 30,
        'order_id' => 31,
        'payment_id' => 32,
        'bank_id' => 60,
        'terminal_id' => 'SALE-TID',
        'commission_profile_id' => 40,
        'organization_id' => 50,
        'amount' => '0.375',
        'bank_response' => ['status' => 'success', 'approvalCode' => 'SALE-APPROVAL'],
        'status' => 'success',
        'country_id' => 1,
        'region_id' => 2,
        'district_id' => 3,
        'city_id' => 4,
        'latitude' => '23.1234567',
        'longitude' => '58.7654321',
        'client_event_id' => 'snapshot-event',
        'occurred_at' => now()->subHour(),
    ])->save();

    expect(app(ForwardCharityDonationAction::class)->forwardSnapshot($donation->fresh()))
        ->toBeTrue();

    Http::assertSent(function (Request $request): bool {
        $timestamp = $request->header('X-Pos-Timestamp')[0] ?? null;

        return $request->url() === 'https://charity.test/api/donations-pos-roundup'
        && is_string($timestamp)
        && ctype_digit($timestamp)
        && $request->header('X-Pos-Signature') === [
            'v1='.hash_hmac(
                'sha256',
                $timestamp.'.'.$request->body(),
                'roundup-test-secret',
            ),
        ]
        && $request->data() === [
            'pos_device_id' => 30,
            'pos_branch_id' => 20,
            'pos_branch_name' => 'Sale-time Branch',
            'commission_profile_id' => 40,
            'organization_id' => 50,
            'amount' => '0.375',
            'receipt' => ['status' => 'success', 'approvalCode' => 'SALE-APPROVAL'],
            'status' => 'success',
            'terminal_id' => 'SALE-TID',
            'bank_id' => 60,
            'pos_reference' => '00000000-0000-4000-8000-000000000001',
            'country_id' => 1,
            'region_id' => 2,
            'district_id' => 3,
            'city_id' => 4,
            'latitude' => '23.1234567',
            'longitude' => '58.7654321',
        ];
    });
});
