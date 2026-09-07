<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Assert;

it('adds five nullable T9 columns while retaining required scan tenancy', function (): void {
    tableSessionSchemaDatabase(function (): void {
        foreach ([
            'pos_qr_session_scans' => ['outcome', 'accuracy_m', 'distance_m'],
            'pos_qr_sessions' => ['released_at', 'handover_from_id'],
        ] as $table => $names) {
            Assert::assertTrue(Schema::hasColumns($table, $names));
            $columns = collect(DB::select("PRAGMA table_info('{$table}')"))->keyBy('name');
            foreach ($names as $name) {
                Assert::assertSame(0, (int) $columns[$name]->notnull);
                Assert::assertNull($columns[$name]->dflt_value);
            }
        }
        $columns = collect(DB::select("PRAGMA table_info('pos_qr_session_scans')"))->keyBy('name');
        Assert::assertSame(1, (int) $columns['company_id']->notnull);
        Assert::assertSame(1, (int) $columns['branch_id']->notnull);
        assertTableSessionIndex('pos_qr_sessions', 'pos_qr_sessions_released_idx', ['released_at'], 0, 0);
        assertTableSessionLegacyIndexes();
    });
});

it('sets only the handover pointer null when its old credential is deleted', function (): void {
    tableSessionSchemaDatabase(function (): void {
        $scope = tableSessionSchemaFixture();
        $old = tableSessionSchemaQr($scope, 'closed', true);
        $new = tableSessionSchemaQr($scope, 'active', true);
        DB::table('pos_qr_sessions')->where('id', $new)->update(['handover_from_id' => $old]);
        $before = (array) DB::table('pos_qr_sessions')->find($new);
        $fk = collect(DB::select("PRAGMA foreign_key_list('pos_qr_sessions')"))->firstWhere('from', 'handover_from_id');
        Assert::assertSame('pos_qr_sessions', $fk->table);
        Assert::assertSame('id', $fk->to);
        Assert::assertSame('SET NULL', $fk->on_delete);
        DB::table('pos_qr_sessions')->where('id', $old)->delete();
        $before['handover_from_id'] = null;
        Assert::assertSame($before, (array) DB::table('pos_qr_sessions')->find($new));
        Assert::assertSame([], DB::select('PRAGMA foreign_key_check'));
    });
});

it('rejects an unknown handover parent and preserves the live credential predicate', function (): void {
    tableSessionSchemaDatabase(function (): void {
        $scope = tableSessionSchemaFixture();
        $id = tableSessionSchemaQr($scope, 'active', true);
        expect(fn () => DB::table('pos_qr_sessions')->where('id', $id)->update([
            'handover_from_id' => 999999,
        ]))->toThrow(QueryException::class);
        expect(fn () => tableSessionSchemaQr($scope, 'active', true))->toThrow(QueryException::class);
        tableSessionSchemaQr($scope, 'closed', true);
        tableSessionSchemaQr($scope, 'closed', true);
        assertTableSessionLegacyIndexes();
    });
});

it('round-trips only the T9 columns preserving all older rows indexes and foreign keys', function (): void {
    tableSessionSchemaDatabase(function (): void {
        $migration = require database_path('migrations/2026_09_07_010000_add_t9_card_columns.php');
        $scope = tableSessionSchemaFixture();
        $old = tableSessionSchemaQr($scope, 'closed', true);
        tableSessionSchemaQr($scope, 'closed', true);
        $new = tableSessionSchemaQr($scope, 'active', true);
        DB::table('pos_qr_sessions')->where('id', $old)->update(['released_at' => '2026-09-07 12:00:00']);
        DB::table('pos_qr_sessions')->where('id', $new)->update(['handover_from_id' => $old]);
        DB::table('pos_qr_session_scans')->insert([
            'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'],
            'table_id' => $scope['table_id'], 'qr_session_id' => $new,
            'role' => 'owner', 'outcome' => 'handover', 'accuracy_m' => 35, 'distance_m' => 10,
            'scanned_at' => '2026-09-07 12:01:00', 'created_at' => '2026-09-07 12:01:00',
        ]);
        $before = [];
        $columns = [];
        foreach ([
            'pos_qr_sessions' => ['released_at', 'handover_from_id'],
            'pos_qr_session_scans' => ['outcome', 'accuracy_m', 'distance_m'],
        ] as $table => $added) {
            $columns[$table] = array_values(array_diff(Schema::getColumnListing($table), $added));
            $before[$table] = DB::table($table)->orderBy('id')->get($columns[$table])->all();
        }
        $migration->down();
        foreach ($columns as $table => $names) {
            Assert::assertSame($names, Schema::getColumnListing($table));
            Assert::assertEquals($before[$table], DB::table($table)->orderBy('id')->get($names)->all());
        }
        Assert::assertNotContains('pos_qr_sessions_released_idx', array_column(Schema::getIndexes('pos_qr_sessions'), 'name'));
        assertTableSessionLegacyIndexes();
        Assert::assertSame([], DB::select('PRAGMA foreign_key_check'));
        $migration->up();
        foreach ($columns as $table => $names) {
            Assert::assertEquals($before[$table], DB::table($table)->orderBy('id')->get($names)->all());
        }
        Assert::assertNull(DB::table('pos_qr_sessions')->where('id', $new)->value('handover_from_id'));
        assertTableSessionColumnsAndForeignKeys();
        assertTableSessionLegacyIndexes();
        Assert::assertSame([], DB::select('PRAGMA foreign_key_check'));
    });
});
