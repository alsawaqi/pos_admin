<?php

declare(strict_types=1);

use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds the bank + charity round-up columns to pos_payments', function (): void {
    expect(Schema::hasColumns('pos_payments', [
        'bank_response',
        'terminal_id',
        'bank_id',
        'device_id',
        'latitude',
        'longitude',
        'roundup_amount',
        'charity_transaction_id',
    ]))->toBeTrue();
});

it('casts bank_response to an array and the money fields to fixed decimals', function (): void {
    $payment = new Payment();
    $payment->forceFill([
        'bank_response' => ['responseCode' => '00', 'approvalCode' => 'A1B2C3'],
        'roundup_amount' => '0.2',
        'latitude' => '23.588',
        'bank_id' => '4',
    ]);

    expect($payment->bank_response)->toBe(['responseCode' => '00', 'approvalCode' => 'A1B2C3']);
    expect($payment->roundup_amount)->toBe('0.200');
    expect($payment->latitude)->toBe('23.5880000');
    expect($payment->bank_id)->toBe(4);
});
