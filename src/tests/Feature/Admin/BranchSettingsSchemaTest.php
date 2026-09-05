<?php

declare(strict_types=1);

use App\Models\Branch;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('owns tenant-scoped branch settings with the required indexes and cascading foreign keys', function (): void {
    expect(Schema::hasTable('pos_branch_settings'))->toBeTrue()
        ->and(Schema::getColumnListing('pos_branch_settings'))->toBe([
            'id', 'company_id', 'branch_id', 'key', 'value', 'created_at', 'updated_at',
        ]);

    $columns = collect(DB::select("PRAGMA table_info('pos_branch_settings')"))->keyBy('name');

    expect((int) $columns->get('company_id')->notnull)->toBe(1)
        ->and((int) $columns->get('branch_id')->notnull)->toBe(1)
        ->and((int) $columns->get('value')->notnull)->toBe(0);

    $indexes = collect(DB::select("PRAGMA index_list('pos_branch_settings')"))->keyBy('name');

    expect($indexes->has('pos_branch_settings_branch_key_unique'))->toBeTrue()
        ->and((int) $indexes->get('pos_branch_settings_branch_key_unique')->unique)->toBe(1)
        ->and(collect(DB::select("PRAGMA index_info('pos_branch_settings_branch_key_unique')"))->pluck('name')->all())
        ->toBe(['branch_id', 'key'])
        ->and($indexes->has('pos_branch_settings_company_key_idx'))->toBeTrue()
        ->and((int) $indexes->get('pos_branch_settings_company_key_idx')->unique)->toBe(0)
        ->and(collect(DB::select("PRAGMA index_info('pos_branch_settings_company_key_idx')"))->pluck('name')->all())
        ->toBe(['company_id', 'key']);

    $foreignKeys = collect(DB::select("PRAGMA foreign_key_list('pos_branch_settings')"))->keyBy('from');

    expect($foreignKeys)->toHaveCount(2);

    foreach (['company_id' => 'pos_companies', 'branch_id' => 'pos_branches'] as $column => $table) {
        expect($foreignKeys->get($column))->not->toBeNull()
            ->and($foreignKeys->get($column)->table)->toBe($table)
            ->and($foreignKeys->get($column)->to)->toBe('id')
            ->and($foreignKeys->get($column)->on_delete)->toBe('CASCADE');
    }
});

it('rolls back and reapplies the branch settings table it owns', function (): void {
    $migration = require database_path('migrations/2026_09_05_010000_create_pos_branch_settings_table.php');

    $migration->down();

    expect(Schema::hasTable('pos_branch_settings'))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumns('pos_branch_settings', [
        'id', 'company_id', 'branch_id', 'key', 'value', 'created_at', 'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasIndex('pos_branch_settings', 'pos_branch_settings_branch_key_unique'))->toBeTrue()
        ->and(Schema::hasIndex('pos_branch_settings', 'pos_branch_settings_company_key_idx'))->toBeTrue();
});

it('rejects a duplicate branch setting key while storing the JSON scalar unchanged', function (): void {
    $branch = Branch::factory()->create();
    $row = [
        'company_id' => $branch->company_id,
        'branch_id' => $branch->id,
        'key' => 'dine_in_round_mode',
        'value' => '"staff_confirm"',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('pos_branch_settings')->insert($row);

    expect(DB::table('pos_branch_settings')->value('value'))->toBe('"staff_confirm"')
        ->and(fn () => DB::table('pos_branch_settings')->insert($row))->toThrow(QueryException::class);
});
