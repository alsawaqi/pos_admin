<?php

declare(strict_types=1);

use App\Actions\Admin\Orders\SalesSummaryAction;
use App\Actions\Admin\Reports\AdminSalesReportAction;
use App\Enums\PlatformRole;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('matches sale plus donation and negative reversal by its own auth while filtering the selected bank', function (): void {
    $this->seed(PlatformRoleSeeder::class);
    actingAsReconAdmin($this, PlatformRole::SuperAdmin->value);
    seedBank(1, 'Oman Arab Bank');
    seedBank(902, 'Another synthetic bank');
    seedCardPayment(['amount' => '5.000', 'roundup_amount' => '0.250', 'softpos_provider' => 'mosambee_dhofar']);
    seedCardPayment([
        'amount' => '-2.000', 'direction' => 'reversal', 'softpos_auth_code' => 'REV-1',
        'softpos_provider' => 'mosambee_dhofar',
    ]);
    seedCardPayment(['amount' => '99.000', 'bank_id' => 902]);
    $this->post('/admin/api/v1/bank-reconciliation/preview', [
        'bank_id' => 1, 'statement_date' => '2026-06-16',
        'file' => oabCsv([['T1', 'A1', '5.250'], ['T1', 'REV-1', '-2.000']]),
    ])->assertOk()->assertJsonPath('data.summary.matched_rows', 2)
        ->assertJsonPath('data.summary.db_only_rows', 0)
        ->assertJsonPath('data.matched.0.payment.softpos_provider', 'mosambee_dhofar')
        ->assertJsonPath('data.matched.1.payment.direction', 'reversal')
        ->assertJsonPath('data.matched.1.payment.auth_code', 'REV-1');
});

it('nets negative ledger amounts while card tender and order counters count sales once', function (): void {
    seedBank(1, 'Oman Arab Bank');
    $paymentId = seedCardPayment(['amount' => '5.000']);
    $payment = (array) DB::table('pos_payments')->where('id', $paymentId)->first();
    unset($payment['id']);
    $payment['uuid'] = (string) Str::uuid();
    $payment['amount'] = '-2.000';
    $payment['direction'] = 'reversal';
    DB::table('pos_payments')->insert($payment);
    $report = app(AdminSalesReportAction::class)->handle(null, now()->startOfDay(), now()->endOfDay());
    expect(collect($report['by_payment_method'])->firstWhere('method', 'card'))
        ->toMatchArray(['amount' => '3.000', 'count' => 1]);
    $summary = app(SalesSummaryAction::class)->handle(now()->startOfDay(), now()->endOfDay());
    expect($summary[0]['sales_count'])->toBe(1);
    expect($summary[0]['card_total'])->toBe('3.000');
});
