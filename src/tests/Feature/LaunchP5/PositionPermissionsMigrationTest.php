<?php

declare(strict_types=1);

/*
 * LAUNCH-P5 — the `position_permissions` data migration: every existing
 * company gets the tick list from the shared defaults
 * (tests/Fixtures/position_permissions_defaults.json, a byte copy of
 * D:\launch-work\p5\shared\position_permissions_defaults.json) and three of
 * today's position lists, per the fixture's migration_from_old_keys.
 */

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function p5PermissionsMigration(): object
{
    return require database_path('migrations/2026_10_04_100007_seed_position_permissions_from_old_lists.php');
}

/** @return array<string, mixed> */
function p5Fixture(): array
{
    return json_decode((string) file_get_contents(base_path('tests/Fixtures/position_permissions_defaults.json')), true, flags: JSON_THROW_ON_ERROR);
}

function p5Setting(int $companyId, string $key, mixed $value): void
{
    DB::table('pos_company_settings')->insert(['company_id' => $companyId, 'key' => $key,
        'value' => json_encode($value), 'created_at' => '2026-09-01 08:00:00', 'updated_at' => '2026-09-01 08:00:00']);
}

/** @return array<string, mixed>|null */
function p5Matrix(int $companyId): ?array
{
    $raw = DB::table('pos_company_settings')->where('company_id', $companyId)->where('key', 'position_permissions')->value('value');

    return $raw === null ? null : json_decode((string) $raw, true);
}

/** @return list<string> positions holding the action */
function p5Holders(array $matrix, string $action): array
{
    return array_values(array_keys(array_filter($matrix, static fn (array $p): bool => $p['actions'][$action] === true)));
}

it('carries the shared fixture\'s positions, actions and defaults', function (): void {
    $migration = p5PermissionsMigration();
    $fixture = p5Fixture();

    expect($migration::POSITIONS)->toBe($fixture['positions'])
        ->and($migration::ACTIONS)->toBe($fixture['actions']);
    foreach ($fixture['positions'] as $position) {
        expect($migration::DEFAULTS[$position]['discount_max_percent'])->toBe($fixture['defaults'][$position]['discount_max_percent'], $position)
            ->and($migration::DEFAULTS[$position]['actions'])->toBe($fixture['defaults'][$position]['actions'], $position);
    }
});

it('writes the tick list for every company from the defaults and the old approval, reports and kitchen lists', function (): void {
    $plain = Company::factory()->create();
    $custom = Company::factory()->create();
    $kitchenEmpty = Company::factory()->create();
    $emptyLists = Company::factory()->create();

    p5Setting($custom->id, 'manager_approval_positions', ['supervisor', 'manager']);
    p5Setting($custom->id, 'reports_positions', ['supervisor']);
    p5Setting($custom->id, 'kitchen_positions', ['waiter']);
    p5Setting($custom->id, 'order_cancel_positions', ['cashier', 'manager']);
    p5Setting($kitchenEmpty->id, 'kitchen_positions', []);
    // Today an empty or unknown-only list reads as "managers only".
    p5Setting($emptyLists->id, 'manager_approval_positions', []);
    p5Setting($emptyLists->id, 'reports_positions', ['owner']);
    $before = DB::table('pos_company_settings')->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all();

    p5PermissionsMigration()->up();

    $defaults = p5Fixture()['defaults'];
    $plainMatrix = p5Matrix($plain->id);
    expect(array_keys($plainMatrix))->toBe(p5Fixture()['positions']);
    foreach ($defaults as $position => $row) {
        expect($plainMatrix[$position]['actions'])->toBe($row['actions'], $position)
            ->and($plainMatrix[$position]['discount_max_percent'])->toBe($row['discount_max_percent'], $position);
    }

    $customMatrix = p5Matrix($custom->id);
    expect(p5Holders($customMatrix, 'approvals.give'))->toBe(['supervisor', 'manager'])
        ->and(p5Holders($customMatrix, 'reports.view'))->toBe(['supervisor'])
        ->and(p5Holders($customMatrix, 'kitchen.screen'))->toBe(['waiter', 'kitchen'])
        // order_cancel_positions is not mapped: order.void_paid keeps its default.
        ->and(p5Holders($customMatrix, 'order.void_paid'))->toBe(['manager'])
        ->and($customMatrix['cashier']['discount_max_percent'])->toBe(10);

    expect(p5Holders(p5Matrix($kitchenEmpty->id), 'kitchen.screen'))->toBe(['kitchen'])
        ->and(p5Holders(p5Matrix($kitchenEmpty->id), 'approvals.give'))->toBe(['manager']);
    expect(p5Holders(p5Matrix($emptyLists->id), 'approvals.give'))->toBe(['manager'])
        ->and(p5Holders(p5Matrix($emptyLists->id), 'reports.view'))->toBe(['manager']);

    // The four old keys stay exactly as they were (old app builds read them).
    $after = DB::table('pos_company_settings')->where('key', '!=', 'position_permissions')->orderBy('id')->get()
        ->map(fn ($r): array => (array) $r)->all();
    expect($after)->toBe($before);
});

it('never overwrites a company\'s existing tick list, is idempotent and is removed on rollback', function (): void {
    $edited = Company::factory()->create();
    $fresh = Company::factory()->create();
    p5Setting($edited->id, 'position_permissions', ['cashier' => ['actions' => ['comp' => true], 'discount_max_percent' => 50]]);

    p5PermissionsMigration()->up();
    $once = DB::table('pos_company_settings')->where('key', 'position_permissions')->orderBy('company_id')->get(['company_id', 'value'])
        ->map(fn ($r): array => [(int) $r->company_id, json_decode((string) $r->value, true)])->all();
    p5PermissionsMigration()->up();
    $twice = DB::table('pos_company_settings')->where('key', 'position_permissions')->orderBy('company_id')->get(['company_id', 'value'])
        ->map(fn ($r): array => [(int) $r->company_id, json_decode((string) $r->value, true)])->all();

    expect($twice)->toBe($once)
        ->and(p5Matrix($edited->id))->toBe(['cashier' => ['actions' => ['comp' => true], 'discount_max_percent' => 50]])
        ->and(p5Matrix($fresh->id)['manager']['actions']['approvals.give'])->toBeTrue();

    p5PermissionsMigration()->down();
    expect(DB::table('pos_company_settings')->where('key', 'position_permissions')->count())->toBe(0);
});
