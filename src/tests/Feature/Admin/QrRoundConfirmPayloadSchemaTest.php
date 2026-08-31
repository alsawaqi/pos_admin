<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds nullable private confirmation state and a unique acceptance cursor', function (): void {
    expect(Schema::hasTable('pos_qr_order_rounds'))->toBeTrue()
        ->and(Schema::hasColumns('pos_qr_order_rounds', [
            'confirm_payload',
            'accepted_seq',
        ]))->toBeTrue()
        ->and(Schema::hasIndex(
            'pos_qr_order_rounds',
            'pos_qr_order_rounds_accepted_seq_unique',
        ))->toBeTrue();

    $columns = collect(DB::select("PRAGMA table_info('pos_qr_order_rounds')"))
        ->keyBy('name');

    foreach (['confirm_payload', 'accepted_seq'] as $columnName) {
        $column = $columns->get($columnName);

        expect($column)->not->toBeNull()
            ->and((int) $column->notnull)->toBe(0)
            ->and($column->dflt_value)->toBeNull();
    }

    $index = collect(DB::select("PRAGMA index_list('pos_qr_order_rounds')"))
        ->first(static fn (object $candidate): bool => $candidate->name === 'pos_qr_order_rounds_accepted_seq_unique');

    expect($index)->not->toBeNull()
        ->and((int) $index->unique)->toBe(1);

    $indexColumns = collect(DB::select("PRAGMA index_info('pos_qr_order_rounds_accepted_seq_unique')"))
        ->pluck('name')
        ->all();

    expect($indexColumns)->toBe(['accepted_seq']);
});

it('rolls back and reapplies both QR round columns and the acceptance index', function (): void {
    $migration = require database_path(
        'migrations/2026_08_31_010000_add_confirm_payload_to_pos_qr_order_rounds.php',
    );

    $migration->down();

    expect(Schema::hasColumn('pos_qr_order_rounds', 'confirm_payload'))->toBeFalse()
        ->and(Schema::hasColumn('pos_qr_order_rounds', 'accepted_seq'))->toBeFalse()
        ->and(Schema::hasIndex(
            'pos_qr_order_rounds',
            'pos_qr_order_rounds_accepted_seq_unique',
        ))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumns('pos_qr_order_rounds', [
        'confirm_payload',
        'accepted_seq',
    ]))->toBeTrue()
        ->and(Schema::hasIndex(
            'pos_qr_order_rounds',
            'pos_qr_order_rounds_accepted_seq_unique',
        ))->toBeTrue();
});

it('owns the PostgreSQL sequence symmetrically and documents both serialized allocators', function (): void {
    $source = file_get_contents(database_path(
        'migrations/2026_08_31_010000_add_confirm_payload_to_pos_qr_order_rounds.php',
    ));

    expect($source)->toBeString()
        ->and(substr_count($source, 'CREATE SEQUENCE pos_qr_order_rounds_accepted_seq_seq'))->toBe(1)
        ->and(substr_count($source, 'DROP SEQUENCE pos_qr_order_rounds_accepted_seq_seq'))->toBe(1)
        ->and($source)->toContain('pg_advisory_xact_lock(814200205)')
        ->and($source)->toContain('holds that lock')
        ->and($source)->toContain("nextval('pos_qr_order_rounds_accepted_seq_seq')")
        ->and($source)->toContain('MAX(accepted_seq) + 1')
        ->and($source)->toContain('DB::transaction(..., 5)');
});
