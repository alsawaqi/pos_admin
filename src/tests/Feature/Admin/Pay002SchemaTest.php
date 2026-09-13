<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('provides bank profiles and a separate reversal ledger schema', function (): void {
    expect(Schema::hasColumns('pos_bank_softpos_profiles', [
        'bank_id', 'softpos_provider', 'softpos_package', 'currency_code',
        'refund_needs_transaction_id', 'void_needs_session_id', 'min_app_version',
        'is_active', 'notes', 'created_by_user_id', 'updated_by_user_id', 'provider_changed_at',
    ]))->toBeTrue();
    expect(Schema::hasColumns('pos_payments', [
        'softpos_provider', 'softpos_package', 'softpos_reported_provider',
        'softpos_mismatch', 'softpos_mismatch_note', 'softpos_transaction_id',
        'softpos_rrn', 'softpos_batch_number', 'softpos_card_masked', 'softpos_card_type',
        'softpos_receipt_at', 'refunded_total', 'voided_at', 'reversal_id', 'direction',
    ]))->toBeTrue();
    expect(Schema::hasColumns('pos_devices', [
        'card_tenders_blocked_reason', 'card_tenders_blocked_at', 'card_tenders_unblocked_at',
    ]))->toBeTrue();
    expect(Schema::hasColumn('pos_orders', 'refunded_total'))->toBeTrue();
    expect(Schema::hasColumns('pos_payment_reversals', [
        'uuid', 'payment_id', 'kind', 'amount', 'amount_baisas', 'status',
        'request_fingerprint', 'client_request_id', 'approved_by_staff_id', 'ledger_payment_id',
    ]))->toBeTrue();
    expect(Schema::hasColumns('pos_payment_reversal_lines', [
        'reversal_id', 'order_item_id', 'qty', 'amount', 'amount_baisas',
        'stock_mode_at_refund', 'returned_to_stock', 'product_stock_movement_id',
    ]))->toBeTrue();
});

it('backfills three banks once preserving bank rows and existing profiles', function (): void {
    DB::table('banks')->insert([
        ['id' => 99001, 'name' => 'Bank Dhofar', 'is_active' => true],
        ['id' => 99002, 'name' => 'Oman Arab Bank', 'is_active' => true],
        ['id' => 99003, 'name' => 'Bank Nizwa', 'is_active' => true],
    ]);
    $banks = DB::table('banks')->orderBy('id')->get()->toJson();
    $migration = require database_path('migrations/2026_09_13_010100_backfill_pos_bank_softpos_profiles.php');
    $migration->up();
    expect(DB::table('pos_bank_softpos_profiles')->where('bank_id', 99001)->value('softpos_provider'))->toBe('mosambee_dhofar');
    expect(DB::table('pos_bank_softpos_profiles')->whereIn('bank_id', [99002, 99003])->pluck('softpos_provider')->all())->toBe(['none', 'none']);
    DB::table('pos_bank_softpos_profiles')->where('bank_id', 99001)->update(['notes' => 'Preserve this configuration']);
    $profiles = DB::table('pos_bank_softpos_profiles')->orderBy('id')->get()->toJson();
    $migration->up();
    expect(DB::table('pos_bank_softpos_profiles')->orderBy('id')->get()->toJson())->toBe($profiles);
    expect(DB::table('banks')->orderBy('id')->get()->toJson())->toBe($banks);
});
