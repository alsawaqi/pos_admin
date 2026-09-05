<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Device;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** @return array<string, int> */
function backfillSeatingScope(?Branch $branch = null): array
{
    $branch ??= Branch::factory()->create();
    $device = Device::factory()->create(['company_id' => $branch->company_id, 'branch_id' => $branch->id]);
    $floorId = DB::table('pos_floors')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $branch->company_id,
        'branch_id' => $branch->id, 'name' => 'Backfill floor '.Str::random(8),
    ]);
    $tableId = DB::table('pos_tables')->insertGetId([
        'uuid' => (string) Str::uuid(), 'company_id' => $branch->company_id,
        'floor_id' => $floorId, 'label' => 'Backfill table', 'qr_token' => Str::random(64),
    ]);

    return ['company_id' => (int) $branch->company_id, 'branch_id' => (int) $branch->id,
        'device_id' => (int) $device->id, 'table_id' => (int) $tableId];
}

function backfillSeatingCredential(array $scope, array $overrides = []): int
{
    return (int) DB::table('pos_qr_sessions')->insertGetId(array_merge($scope, [
        'uuid' => (string) Str::uuid(), 'token' => Str::random(64),
        'token_expires_at' => '2026-09-05 10:01:00', 'status' => 'active',
        'expires_at' => '2026-09-05 16:00:00',
        'created_at' => '2026-09-05 10:00:00', 'updated_at' => '2026-09-05 10:00:00',
    ], $overrides));
}

function backfillSeatingOrder(array $scope, ?int $sessionId = null, array $overrides = []): int
{
    return (int) DB::table('pos_orders')->insertGetId(array_merge([
        'uuid' => (string) Str::uuid(), 'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'], 'table_id' => $scope['table_id'],
        'qr_session_id' => $sessionId, 'order_type' => 'dine_in', 'source' => 'qr_web',
        'status' => 'awaiting_payment', 'receipt_number' => 'LEGACY-0007',
        'temp_reference' => 'T-0905-077', 'opened_at' => '2026-09-05 10:00:00',
        'created_at' => '2026-09-05 10:00:00', 'updated_at' => '2026-09-05 10:30:00',
    ], $overrides));
}

function backfillSeatingRow(array $scope, array $overrides = []): int
{
    return (int) DB::table('pos_table_sessions')->insertGetId(array_merge([
        'uuid' => (string) Str::uuid(), 'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'], 'table_id' => $scope['table_id'],
        'status' => 'open', 'origin' => 'station', 'opened_at' => '2026-09-05 10:00:00',
        'expires_at' => '2026-09-05 16:00:00',
    ], $overrides));
}

function backfillSeatingSnapshot(): array
{
    $snapshot = [];
    foreach (['pos_qr_sessions', 'pos_orders', 'pos_qr_order_rounds', 'pos_order_items',
        'pos_table_sessions', 'pos_temp_reference_sequences', 'pos_order_sequences', 'pos_table_session_events'] as $table) {
        $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    return $snapshot;
}

it('previews without writes then applies and reruns without altering identities payloads or horizons', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-05 12:00:00', 'UTC'));
    $scope = backfillSeatingScope();
    $session = backfillSeatingCredential($scope);
    $order = backfillSeatingOrder($scope, $session);
    $secondScope = backfillSeatingScope(Branch::findOrFail($scope['branch_id']));
    $secondSession = backfillSeatingCredential($secondScope);
    foreach (['accepted', 'pending_staff'] as $index => $status) {
        DB::table('pos_qr_order_rounds')->insert([
            'qr_session_id' => $session, 'order_id' => $order, 'round_no' => $index + 1,
            'status' => $status, 'client_request_id' => 'backfill-round-'.$index,
            'priced_lines' => '{"preserve":"exact bytes", "amount":1}',
            'subtotal_baisas' => 100, 'tax_baisas' => 5, 'total_baisas' => 105,
            'submitted_at' => '2026-09-05 10:15:00',
        ]);
    }
    $before = backfillSeatingSnapshot();
    DB::enableQueryLog();
    expect(Artisan::call('qr:backfill-table-sessions', ['--company' => (string) $scope['company_id']]))->toBe(0);
    $dryRun = Artisan::output();
    $writes = collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query['query']) === 1);
    DB::disableQueryLog();
    expect($writes)->toHaveCount(0)->and(backfillSeatingSnapshot())->toBe($before)
        ->and($dryRun)->toBe("scanned=2 would_create=2 linked_orders=1 linked_rounds=2 allocated=1 skipped_attached=0 conflicts=0 (table_has_live_seating=0 tenant_mismatch=0 order_table_mismatch=0 table_missing=0)\n");

    expect(Artisan::call('qr:backfill-table-sessions', ['--company' => (string) $scope['company_id'], '--apply' => true, '--chunk' => '1']))->toBe(0)
        ->and(Artisan::output())->toBe("scanned=2 created=2 linked_orders=1 linked_rounds=2 allocated=1 skipped_attached=0 conflicts=0 (table_has_live_seating=0 tenant_mismatch=0 order_table_mismatch=0 table_missing=0)\n");
    $seating = DB::table('pos_table_sessions')->where('table_id', $scope['table_id'])->first();
    expect($seating->status)->toBe('billing')->and($seating->origin)->toBe('station')
        ->and((int) $seating->opened_by_device_id)->toBe($scope['device_id'])
        ->and((int) $seating->order_id)->toBe($order)->and($seating->temp_reference)->toBe('T-0905-077')
        ->and($seating->opened_at)->toBe('2026-09-05 10:00:00')->and($seating->expires_at)->toBe('2026-09-05 16:00:00')
        ->and($seating->billing_at)->toBe('2026-09-05 10:30:00')->and($seating->closed_at)->toBeNull()
        ->and($seating->closed_by_device_id)->toBeNull()->and($seating->close_reason)->toBeNull()
        ->and((int) DB::table('pos_qr_sessions')->where('id', $session)->value('table_session_id'))->toBe((int) $seating->id)
        ->and((int) DB::table('pos_orders')->where('id', $order)->value('table_session_id'))->toBe((int) $seating->id);
    $after = backfillSeatingSnapshot();
    foreach ($before['pos_qr_order_rounds'] as $index => $round) {
        $expected = $round;
        $expected['table_session_id'] = (int) $seating->id;
        expect($after['pos_qr_order_rounds'][$index])->toBe($expected);
    }
    foreach ($before['pos_orders'] as $index => $bill) {
        $bill['table_session_id'] = (int) $seating->id;
        expect($after['pos_orders'][$index])->toBe($bill);
    }
    $fresh = DB::table('pos_table_sessions')->where('table_id', $secondScope['table_id'])->first();
    expect($fresh->status)->toBe('open')->and($fresh->order_id)->toBeNull()->and($fresh->billing_at)->toBeNull()
        ->and($fresh->temp_reference)->toBe('T-0905-001')
        ->and((int) DB::table('pos_qr_sessions')->where('id', $secondSession)->value('table_session_id'))->toBe((int) $fresh->id)
        ->and((int) DB::table('pos_temp_reference_sequences')->value('next_number'))->toBe(2)
        ->and(DB::table('pos_order_sequences')->count())->toBe(0)->and(DB::table('pos_table_session_events')->count())->toBe(0);
    Carbon::setTestNow(Carbon::parse('2026-09-06 12:00:00', 'UTC'));
    expect(Artisan::call('qr:backfill-table-sessions', ['--company' => (string) $scope['company_id'], '--apply' => true]))->toBe(0)
        ->and(Artisan::output())->toBe("scanned=0 created=0 linked_orders=0 linked_rounds=0 allocated=0 skipped_attached=0 conflicts=0 (table_has_live_seating=0 tenant_mismatch=0 order_table_mismatch=0 table_missing=0)\n")
        ->and(backfillSeatingSnapshot())->toBe($after);
    Carbon::setTestNow();
});

it('copies an existing order reference including NULL without allocating or renumbering', function (?string $reference): void {
    $scope = backfillSeatingScope();
    $session = backfillSeatingCredential($scope);
    $order = backfillSeatingOrder($scope, $session, ['temp_reference' => $reference, 'status' => 'open']);
    expect(Artisan::call('qr:backfill-table-sessions', ['--company' => (string) $scope['company_id'], '--apply' => true]))->toBe(0)
        ->and(DB::table('pos_table_sessions')->value('temp_reference'))->toBe($reference)
        ->and(DB::table('pos_table_sessions')->value('status'))->toBe('open')
        ->and(DB::table('pos_orders')->where('id', $order)->value('temp_reference'))->toBe($reference)
        ->and(DB::table('pos_orders')->where('id', $order)->value('receipt_number'))->toBe('LEGACY-0007')
        ->and(DB::table('pos_temp_reference_sequences')->count())->toBe(0);
})->with([null, 'T-0904-077']);

it('materialises unpaid orphans only with the flag and derives the six hour horizon from opened_at', function (): void {
    $scope = backfillSeatingScope();
    $order = backfillSeatingOrder($scope, null, ['status' => 'held', 'temp_reference' => null]);
    expect(Artisan::call('qr:backfill-table-sessions', ['--company' => (string) $scope['company_id'], '--apply' => true]))->toBe(0)
        ->and(DB::table('pos_table_sessions')->count())->toBe(0);
    expect(Artisan::call('qr:backfill-table-sessions', ['--company' => (string) $scope['company_id'], '--apply' => true, '--include-orphans' => true]))->toBe(0);
    $seating = DB::table('pos_table_sessions')->first();
    expect((int) $seating->order_id)->toBe($order)->and($seating->opened_by_device_id)->toBeNull()
        ->and($seating->status)->toBe('billing')->and($seating->billing_at)->toBe('2026-09-05 10:30:00')
        ->and($seating->opened_at)->toBe('2026-09-05 10:00:00')->and($seating->expires_at)->toBe('2026-09-05 16:00:00')
        ->and($seating->temp_reference)->toBeNull()->and(DB::table('pos_temp_reference_sequences')->count())->toBe(0)
        ->and(DB::table('pos_qr_sessions')->count())->toBe(0);
});

it('uses the UTC allocation day even when the application timezone is Muscat', function (): void {
    $originalTimezone = date_default_timezone_get();
    config(['app.timezone' => 'Asia/Muscat']);
    date_default_timezone_set('Asia/Muscat');
    Carbon::setTestNow(Carbon::parse('2026-09-06 01:30:00', 'Asia/Muscat'));
    try {
        $scope = backfillSeatingScope();
        backfillSeatingCredential($scope);
        expect(Artisan::call('qr:backfill-table-sessions', ['--company' => (string) $scope['company_id'], '--apply' => true]))->toBe(0)
            ->and(DB::table('pos_table_sessions')->value('temp_reference'))->toBe('T-0905-001');
        $sequence = DB::table('pos_temp_reference_sequences')->first();
        expect((int) $sequence->company_id)->toBe($scope['company_id'])->and((int) $sequence->branch_id)->toBe($scope['branch_id'])
            ->and($sequence->seq_date)->toBe('2026-09-05')->and((int) $sequence->next_number)->toBe(2);
    } finally {
        Carbon::setTestNow();
        date_default_timezone_set($originalTimezone);
    }
});

it('reports a live seating conflict without rolling back other created rows', function (): void {
    $scope = backfillSeatingScope();
    $session = backfillSeatingCredential($scope);
    $seating = backfillSeatingRow($scope, ['status' => 'closing']);
    $otherScope = backfillSeatingScope(Branch::findOrFail($scope['branch_id']));
    backfillSeatingCredential($otherScope);
    expect(Artisan::call('qr:backfill-table-sessions', ['--company' => (string) $scope['company_id'], '--apply' => true]))->toBe(1);
    $output = Artisan::output();
    expect($output)->toContain("conflict:table_has_live_seating session_id={$session} order_id=NULL table_id={$scope['table_id']} seating_id={$seating}")
        ->and($output)->toContain('scanned=2 created=1 linked_orders=0 linked_rounds=0 allocated=1 skipped_attached=0 conflicts=1')
        ->and(DB::table('pos_table_sessions')->count())->toBe(2)
        ->and(DB::table('pos_qr_sessions')->where('id', $session)->value('table_session_id'))->toBeNull();
});

it('reports tenant disagreement with the table floor without writes', function (bool $onOrder): void {
    $scope = backfillSeatingScope();
    $other = backfillSeatingScope();
    $session = backfillSeatingCredential($scope);
    if ($onOrder) {
        backfillSeatingOrder($scope, $session, ['company_id' => $other['company_id'], 'branch_id' => $other['branch_id']]);
    } else {
        DB::table('pos_qr_sessions')->where('id', $session)->update(['branch_id' => $other['branch_id']]);
    }
    $before = backfillSeatingSnapshot();
    expect(Artisan::call('qr:backfill-table-sessions', ['--company' => (string) $scope['company_id'], '--apply' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('conflict:tenant_mismatch')->and(backfillSeatingSnapshot())->toBe($before);
})->with([false, true]);

it('reports latest order table mismatch without attaching or allocating', function (): void {
    $scope = backfillSeatingScope();
    $other = backfillSeatingScope(Branch::findOrFail($scope['branch_id']));
    $session = backfillSeatingCredential($scope);
    backfillSeatingOrder($scope, $session, ['table_id' => $other['table_id']]);
    $before = backfillSeatingSnapshot();
    expect(Artisan::call('qr:backfill-table-sessions', ['--company' => (string) $scope['company_id'], '--apply' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('conflict:order_table_mismatch')->and(backfillSeatingSnapshot())->toBe($before);
});

it('reports a table hard deleted between the candidate scan and its table lock', function (): void {
    $scope = backfillSeatingScope();
    backfillSeatingCredential($scope);
    $deleted = false;
    DB::listen(function (QueryExecuted $query) use ($scope, &$deleted): void {
        if (! $deleted && str_contains($query->sql, 'select * from "pos_qr_sessions"') && str_contains($query->sql, 'limit 200')) {
            $deleted = true;
            DB::table('pos_tables')->where('id', $scope['table_id'])->delete();
        }
    });
    expect(Artisan::call('qr:backfill-table-sessions', ['--company' => (string) $scope['company_id'], '--apply' => true]))->toBe(1)
        ->and($deleted)->toBeTrue()->and(Artisan::output())->toContain('conflict:table_missing')
        ->and(DB::table('pos_table_sessions')->count())->toBe(0)->and(DB::table('pos_temp_reference_sequences')->count())->toBe(0);
});

it('skips a credential attached between scan and lock without allocating again', function (): void {
    $scope = backfillSeatingScope();
    $session = backfillSeatingCredential($scope);
    $seating = backfillSeatingRow($scope);
    $attached = false;
    DB::listen(function (QueryExecuted $query) use ($session, $seating, &$attached): void {
        if (! $attached && str_contains($query->sql, 'select * from "pos_qr_sessions"') && str_contains($query->sql, 'limit 200')) {
            $attached = true;
            DB::table('pos_qr_sessions')->where('id', $session)->update(['table_session_id' => $seating]);
        }
    });
    expect(Artisan::call('qr:backfill-table-sessions', ['--company' => (string) $scope['company_id'], '--apply' => true]))->toBe(0)
        ->and($attached)->toBeTrue()->and(Artisan::output())->toContain('scanned=1 created=0 linked_orders=0 linked_rounds=0 allocated=0 skipped_attached=1 conflicts=0')
        ->and(DB::table('pos_table_sessions')->count())->toBe(1)->and(DB::table('pos_temp_reference_sequences')->count())->toBe(0);
});

it('honours company branch and stable id boundaries without touching other tenants', function (): void {
    $scope = backfillSeatingScope();
    $selected = backfillSeatingCredential($scope);
    $second = backfillSeatingScope(Branch::findOrFail($scope['branch_id']));
    $beyond = backfillSeatingCredential($second);
    $third = backfillSeatingScope(Branch::factory()->create(['company_id' => $scope['company_id']]));
    $otherBranch = backfillSeatingCredential($third);
    $other = backfillSeatingScope();
    $otherCompany = backfillSeatingCredential($other);
    $otherBefore = (array) DB::table('pos_qr_sessions')->where('id', $otherCompany)->first();
    expect(Artisan::call('qr:backfill-table-sessions', ['--company' => (string) $scope['company_id'], '--branch' => (string) $scope['branch_id'], '--max-session-id' => (string) $selected, '--apply' => true]))->toBe(0)
        ->and(DB::table('pos_qr_sessions')->where('id', $selected)->value('table_session_id'))->not->toBeNull();
    foreach ([$beyond, $otherBranch, $otherCompany] as $id) {
        expect(DB::table('pos_qr_sessions')->where('id', $id)->value('table_session_id'))->toBeNull();
    }
    expect((array) DB::table('pos_qr_sessions')->where('id', $otherCompany)->first())->toBe($otherBefore)
        ->and(DB::table('pos_table_sessions')->count())->toBe(1);
});

it('restarts before taking a newly appeared order lock and copies its legacy NULL reference', function (): void {
    $scope = backfillSeatingScope();
    $session = backfillSeatingCredential($scope);
    $inserted = false;
    $order = null;
    $sessionReads = 0;
    DB::listen(function (QueryExecuted $query) use ($scope, $session, &$inserted, &$order, &$sessionReads): void {
        if (str_contains($query->sql, 'select * from "pos_qr_sessions"') && str_contains($query->sql, 'limit 1')) {
            $sessionReads++;
        }
        if (! $inserted && str_contains($query->sql, 'select * from "pos_orders"') && str_contains($query->sql, 'order by "id" desc limit 1')) {
            $inserted = true;
            $order = backfillSeatingOrder($scope, $session, ['temp_reference' => null]);
        }
    });
    expect(Artisan::call('qr:backfill-table-sessions', ['--company' => (string) $scope['company_id'], '--apply' => true]))->toBe(0)
        ->and($inserted)->toBeTrue()->and($sessionReads)->toBe(2)
        ->and((int) DB::table('pos_table_sessions')->value('order_id'))->toBe($order)
        ->and(DB::table('pos_table_sessions')->value('temp_reference'))->toBeNull()
        ->and(DB::table('pos_temp_reference_sequences')->count())->toBe(0)
        ->and(DB::table('pos_table_sessions')->count())->toBe(1);
});

it('does not retroactively seat terminal or quick credentials or non-QR and terminal orphans', function (): void {
    $scope = backfillSeatingScope();
    foreach (['closed', 'expired'] as $status) {
        backfillSeatingCredential($scope, ['status' => $status]);
    }
    backfillSeatingCredential($scope, ['table_id' => null]);
    backfillSeatingOrder($scope, null, ['status' => 'paid']);
    backfillSeatingOrder($scope, null, ['source' => 'device']);
    backfillSeatingOrder($scope, null, ['order_type' => 'quick']);
    backfillSeatingOrder($scope, null, ['table_id' => null]);
    $before = backfillSeatingSnapshot();
    expect(Artisan::call('qr:backfill-table-sessions', ['--company' => (string) $scope['company_id'], '--apply' => true, '--include-orphans' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('scanned=0 created=0 linked_orders=0 linked_rounds=0 allocated=0 skipped_attached=0 conflicts=0')
        ->and(backfillSeatingSnapshot())->toBe($before);
});

it('applies a stable orphan id boundary independently of the credential boundary', function (): void {
    $scope = backfillSeatingScope();
    $first = backfillSeatingOrder($scope);
    $otherScope = backfillSeatingScope(Branch::findOrFail($scope['branch_id']));
    $second = backfillSeatingOrder($otherScope);
    expect(Artisan::call('qr:backfill-table-sessions', ['--company' => (string) $scope['company_id'], '--apply' => true,
        '--include-orphans' => true, '--max-session-id' => '0', '--max-order-id' => (string) $first]))->toBe(0)
        ->and(DB::table('pos_table_sessions')->count())->toBe(1)
        ->and(DB::table('pos_orders')->where('id', $first)->value('table_session_id'))->not->toBeNull()
        ->and(DB::table('pos_orders')->where('id', $second)->value('table_session_id'))->toBeNull();
});

it('checks the table company as well as its floor before attaching', function (): void {
    $scope = backfillSeatingScope();
    $other = backfillSeatingScope();
    backfillSeatingCredential($scope);
    DB::table('pos_tables')->where('id', $scope['table_id'])->update(['company_id' => $other['company_id']]);
    $before = backfillSeatingSnapshot();
    expect(Artisan::call('qr:backfill-table-sessions', ['--company' => (string) $scope['company_id'], '--apply' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('conflict:tenant_mismatch')->and(backfillSeatingSnapshot())->toBe($before);
});

it('skips an already attached order rather than creating a second identity through its unlinked credential', function (): void {
    $scope = backfillSeatingScope();
    $session = backfillSeatingCredential($scope);
    $seating = backfillSeatingRow($scope, ['status' => 'closed', 'closed_at' => '2026-09-05 12:00:00', 'close_reason' => 'paid']);
    $order = backfillSeatingOrder($scope, $session, ['status' => 'paid', 'table_session_id' => $seating]);
    DB::table('pos_table_sessions')->where('id', $seating)->update(['order_id' => $order]);
    $before = backfillSeatingSnapshot();
    expect(Artisan::call('qr:backfill-table-sessions', ['--company' => (string) $scope['company_id'], '--apply' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('scanned=1 created=0 linked_orders=0 linked_rounds=0 allocated=0 skipped_attached=1 conflicts=0')
        ->and(backfillSeatingSnapshot())->toBe($before);
});

it('previews competing orphans with the same counters as apply and no invented numeric seating id', function (): void {
    $scope = backfillSeatingScope();
    backfillSeatingOrder($scope);
    backfillSeatingOrder($scope);
    $before = backfillSeatingSnapshot();
    $options = ['--company' => (string) $scope['company_id'], '--include-orphans' => true];
    expect(Artisan::call('qr:backfill-table-sessions', $options))->toBe(1);
    $preview = Artisan::output();
    expect($preview)->toContain('scanned=2 would_create=1 linked_orders=1 linked_rounds=0 allocated=0 skipped_attached=0 conflicts=1')
        ->and($preview)->toContain('seating_id=would_create')->and(backfillSeatingSnapshot())->toBe($before);
    expect(Artisan::call('qr:backfill-table-sessions', array_merge($options, ['--apply' => true])))->toBe(1);
    $applied = Artisan::output();
    expect($applied)->toContain('scanned=2 created=1 linked_orders=1 linked_rounds=0 allocated=0 skipped_attached=0 conflicts=1')
        ->and($applied)->not->toContain('seating_id=would_create')
        ->and(DB::table('pos_table_sessions')->count())->toBe(1)->and(DB::table('pos_temp_reference_sequences')->count())->toBe(0);
});

it('rejects invalid options with exit two and no writes', function (string $invalid): void {
    $scope = backfillSeatingScope();
    $other = backfillSeatingScope();
    backfillSeatingCredential($scope);
    $options = ['--company' => (string) $scope['company_id'], '--apply' => true];
    $options = array_merge($options, match ($invalid) {
        'missing_company' => ['--company' => null],
        'unknown_company' => ['--company' => '99999999'],
        'foreign_branch' => ['--branch' => (string) $other['branch_id']],
        'zero_chunk' => ['--chunk' => '0'],
        'large_chunk' => ['--chunk' => '1001'],
        'text_chunk' => ['--chunk' => 'two'],
        'negative_boundary' => ['--max-session-id' => '-1'],
        'invalid_order_boundary' => ['--max-order-id' => 'bad'],
    });
    $before = backfillSeatingSnapshot();
    expect(Artisan::call('qr:backfill-table-sessions', $options))->toBe(2)
        ->and(Artisan::output())->toContain('Invalid options:')->and(backfillSeatingSnapshot())->toBe($before);
})->with(['missing_company', 'unknown_company', 'foreign_branch', 'zero_chunk', 'large_chunk', 'text_chunk', 'negative_boundary', 'invalid_order_boundary']);
