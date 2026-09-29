<?php

declare(strict_types=1);

use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

uses()
    ->beforeEach(function (): void {
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    })
    ->in('Feature');

/**
 * Complete the older effects fixtures with immutable acquiring snapshots, then
 * upload an actual bank CSV. W8 matching itself is tested in BankReconciliationTest.
 */
function seedStatementSnapshotAndUploadForEffectsTest(TestCase $test, array $ids): string
{
    DB::table('banks')->insertOrIgnore([
        'id' => 9001, 'name' => 'Oman Arab Bank', 'short_name' => 'OAB', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $rows = ['TRANSACTION_DATE,TERMINAL_ID,BRANCH_ID,CARD_NUMBER,CARD_TYPE,TRANSACTION_TYPE,TRANSACTION_REFERENCE,RETRIEVAL_REF_NUMBER,AUTH_CODE,TRANSACTION_AMOUNT,DISCOUNTRATE_AMOUNT,VAT_AMOUNT,NET_AMOUNT,RELATED_REFERENCE,SETTLEMENTDATE'];
    $date = now()->setTimezone('Asia/Muscat')->format('Y-m-d');
    foreach ($ids as $id) {
        DB::table('pos_payments')->where('id', $id)->update([
            'bank_id' => 9001, 'terminal_id' => 'FIXTURE-'.$id, 'softpos_auth_code' => 'AUTH-'.$id,
            'captured_at' => now(),
        ]);
        $payment = DB::table('pos_payments')->find($id);
        $gross = number_format((float) $payment->amount + (float) $payment->roundup_amount, 3, '.', '');
        $rows[] = implode(',', [$date.' 10:00', $payment->terminal_id, 'B1', '411111******1111', 'VISA', 'PURCHASE',
            'REF'.$id, 'RRN'.$id, $payment->softpos_auth_code, $gross, '0', '0', $gross, '', $date]);
    }

    return $test->post('/admin/api/v1/bank-reconciliation/preview', [
        'bank_id' => 9001, 'statement_date' => $date,
        'file' => UploadedFile::fake()->createWithContent('bank.csv', implode("\n", $rows)."\n"),
    ])->assertOk()->assertJsonPath('data.summary.matched_rows', count($ids))->json('data.statement_token');
}
