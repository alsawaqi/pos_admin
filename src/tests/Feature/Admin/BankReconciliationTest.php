<?php

declare(strict_types=1);

use App\Enums\PlatformRole;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
});

function actingAsReconAdmin(\Tests\TestCase $test, string $role): User
{
    /** @var User $user */
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(TenantContext::PLATFORM_TEAM_ID);
    $user->assignRole($role);
    $test->actingAs($user);

    return $user;
}

function seedBank(int $id, string $name): void
{
    DB::table('banks')->insert([
        'id' => $id, 'name' => $name, 'short_name' => $name, 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function seedCardPayment(array $attrs): int
{
    // pos_orders FKs to companies + pos_branches, so seed real parents.
    $company = \App\Models\Company::factory()->create();
    $branch = \App\Models\Branch::factory()->create(['company_id' => $company->id]);

    $orderId = DB::table('pos_orders')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $company->id, 'branch_id' => $branch->id,
        'order_type' => 'quick', 'status' => 'paid', 'source' => 'main_pos',
        'subtotal' => '5.000', 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => '5.000',
        'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return DB::table('pos_payments')->insertGetId(array_merge([
        'uuid' => (string) Str::uuid(), 'order_id' => $orderId, 'method' => 'card',
        'amount' => '5.000', 'status' => 'success', 'pending_reconciliation' => false,
        'terminal_id' => 'T1', 'bank_id' => 1, 'softpos_auth_code' => 'A1',
        'captured_at' => '2026-06-16 10:00:00', 'created_at' => now(), 'updated_at' => now(),
    ], $attrs));
}

function oabCsv(array $dataRows): UploadedFile
{
    $header = 'TRANSACTION_DATE,TERMINAL_ID,BRANCH_ID,CARD_NUMBER,CARD_TYPE,TRANSACTION_TYPE,TRANSACTION_REFERENCE,RETRIEVAL_REF_NUMBER,AUTH_CODE,TRANSACTION_AMOUNT,DISCOUNTRATE_AMOUNT,VAT_AMOUNT,NET_AMOUNT,RELATED_REFERENCE,SETTLEMENTDATE';
    $lines = [$header];
    foreach ($dataRows as $r) {
        // [terminal, auth, gross, net?] -> a full 15-col row, settlement date fixed.
        // net defaults to gross (fee 0); pass a 4th element for a real fee.
        $lines[] = implode(',', [
            '6/16/2026 10:00', $r[0], 'BR1', '411111******1111', 'VISA', 'PURCHASE',
            'REF'.$r[1], 'RRN'.$r[1], $r[1], $r[2], '0', '0', $r[3] ?? $r[2], '', '2026-06-16',
        ]);
    }

    return UploadedFile::fake()->createWithContent('oab.csv', implode("\n", $lines)."\n");
}

function dhofarXlsx(string $headerDate, array $dataRows): UploadedFile
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Table 1');
    $sheet->setCellValue('E4', $headerDate);
    $sheet->fromArray(['DATE', 'TERMINAL ID', 'AUTHO CODE', 'GROSS AMOUNT', 'CARD NO'], null, 'A6');
    $row = 7;
    foreach ($dataRows as $r) {
        $sheet->fromArray([$headerDate, $r[0], $r[1], $r[2], '411111******1111'], null, 'A'.$row);
        $row++;
    }
    $path = tempnam(sys_get_temp_dir(), 'recon').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'dhofar.xlsx', null, null, true);
}

it('reconciles an OAB CSV statement against pos_payments', function (): void {
    actingAsReconAdmin($this, PlatformRole::SuperAdmin->value);
    seedBank(1, 'Oman Arab Bank');
    seedCardPayment(['terminal_id' => 'T1', 'softpos_auth_code' => 'A1', 'amount' => '5.000', 'bank_id' => 1]); // matched
    seedCardPayment(['terminal_id' => 'T2', 'softpos_auth_code' => 'A2', 'amount' => '2.000', 'bank_id' => 1]); // db_only

    $file = oabCsv([
        ['T1', 'A1', '5.000'],  // matches
        ['T9', 'A9', '3.000'],  // missing_in_db
    ]);

    $res = $this->post('/admin/api/v1/bank-reconciliation/preview', [
        'bank_id' => 1, 'statement_date' => '2026-06-16', 'file' => $file,
    ])->assertOk();

    $res->assertJsonPath('data.parser', 'bank_oab_csv_v1');
    $res->assertJsonPath('data.summary.matched_rows', 1);
    $res->assertJsonPath('data.summary.missing_in_db_rows', 1);
    $res->assertJsonPath('data.summary.db_only_rows', 1);
    expect($res->json('data.matched.0.payment.terminal_id'))->toBe('T1');
});

it('flags an amount mismatch', function (): void {
    actingAsReconAdmin($this, PlatformRole::SuperAdmin->value);
    seedBank(1, 'Oman Arab Bank');
    seedCardPayment(['terminal_id' => 'T1', 'softpos_auth_code' => 'A1', 'amount' => '5.000', 'bank_id' => 1]);

    $res = $this->post('/admin/api/v1/bank-reconciliation/preview', [
        'bank_id' => 1, 'statement_date' => '2026-06-16',
        'file' => oabCsv([['T1', 'A1', '9.999']]),
    ])->assertOk();

    $res->assertJsonPath('data.summary.matched_rows', 0);
    $res->assertJsonPath('data.summary.amount_mismatch_rows', 1);
});

it('reconciles a Bank Dhofar xlsx statement', function (): void {
    actingAsReconAdmin($this, PlatformRole::SuperAdmin->value);
    seedBank(2, 'Bank Dhofar');
    seedCardPayment(['terminal_id' => 'TD1', 'softpos_auth_code' => 'AD1', 'amount' => '5.000', 'bank_id' => 2]);

    $res = $this->post('/admin/api/v1/bank-reconciliation/preview', [
        'bank_id' => 2, 'statement_date' => '2026-06-16',
        'file' => dhofarXlsx('16/06/2026', [['TD1', 'AD1', '5.000']]),
    ])->assertOk();

    $res->assertJsonPath('data.parser', 'bank_dhofar_v1');
    $res->assertJsonPath('data.summary.matched_rows', 1);
});

it('commits matched payments as reconciled', function (): void {
    actingAsReconAdmin($this, PlatformRole::SuperAdmin->value);
    seedBank(1, 'Oman Arab Bank');
    $paymentId = seedCardPayment(['terminal_id' => 'T1', 'softpos_auth_code' => 'A1', 'amount' => '5.000', 'bank_id' => 1, 'status' => 'pending_reconciliation', 'pending_reconciliation' => true]);

    $this->postJson('/admin/api/v1/bank-reconciliation/commit', ['payment_ids' => [$paymentId]])
        ->assertOk()
        ->assertJsonPath('data.reconciled', 1);

    $this->assertDatabaseHas('pos_payments', [
        'id' => $paymentId, 'pending_reconciliation' => false, 'status' => 'success',
    ]);
});

it('captures the actual bank fee (gross - net) from an OAB statement + persists it on commit (A2)', function (): void {
    actingAsReconAdmin($this, PlatformRole::SuperAdmin->value);
    seedBank(1, 'Oman Arab Bank');
    $paymentId = seedCardPayment(['terminal_id' => 'T1', 'softpos_auth_code' => 'A1', 'amount' => '5.000', 'bank_id' => 1, 'status' => 'pending_reconciliation', 'pending_reconciliation' => true]);

    // Statement: gross 5.000, net 4.850 -> the bank took 0.150.
    $preview = $this->post('/admin/api/v1/bank-reconciliation/preview', [
        'bank_id' => 1, 'statement_date' => '2026-06-16',
        'file' => oabCsv([['T1', 'A1', '5.000', '4.850']]),
    ])->assertOk();
    expect((float) $preview->json('data.matched.0.bank_fee'))->toBe(0.15);

    $this->postJson('/admin/api/v1/bank-reconciliation/commit', [
        'payment_ids' => [$paymentId],
        'fees' => [(string) $paymentId => '0.150'],
    ])->assertOk();

    expect((float) DB::table('pos_payments')->where('id', $paymentId)->value('bank_fee'))->toBe(0.15);
});

it('floors a captured bank fee at 0 when a statement row is a credit (net > gross) (A2)', function (): void {
    actingAsReconAdmin($this, PlatformRole::SuperAdmin->value);
    seedBank(1, 'Oman Arab Bank');
    seedCardPayment(['terminal_id' => 'T1', 'softpos_auth_code' => 'A1', 'amount' => '5.000', 'bank_id' => 1]);

    // A rebate/credit row nets MORE than gross — the fee must clamp to 0, never
    // go negative (which would poison the merchant residual or 422-block commit).
    $preview = $this->post('/admin/api/v1/bank-reconciliation/preview', [
        'bank_id' => 1, 'statement_date' => '2026-06-16',
        'file' => oabCsv([['T1', 'A1', '5.000', '5.100']]),
    ])->assertOk();

    expect((float) $preview->json('data.matched.0.bank_fee'))->toBe(0.0);
});

it('forbids a non-settings user', function (): void {
    actingAsReconAdmin($this, PlatformRole::Support->value);
    seedBank(1, 'Oman Arab Bank');

    $this->post('/admin/api/v1/bank-reconciliation/preview', [
        'bank_id' => 1, 'statement_date' => '2026-06-16',
        'file' => oabCsv([['T1', 'A1', '5.000']]),
    ])->assertForbidden();
});
