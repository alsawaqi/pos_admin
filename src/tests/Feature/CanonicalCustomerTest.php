<?php

declare(strict_types=1);

use App\Models\Company;
use App\Support\CanonicalPhone;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(getenv('DB_CONNECTION') === 'pgsql' ? DatabaseTransactions::class : RefreshDatabase::class);

it('has additive customer columns and uses the common canonical vectors', function (): void {
    expect(Schema::hasColumn('pos_customers', 'phone_canonical'))->toBeTrue();
    expect(Schema::hasColumn('pos_customers', 'merged_into_customer_id'))->toBeTrue();
    foreach (json_decode(file_get_contents(base_path('tests/Fixtures/canonical-phone-vectors.json')), true, flags: JSON_THROW_ON_ERROR) as [$raw, $expected]) {
        expect(CanonicalPhone::of($raw))->toBe($expected);
    }
});

it('reverses and backfills live and deleted customers across chunk boundaries without changing their identities', function (): void {
    $company = Company::factory()->create();
    $migration = require database_path('migrations/2026_09_26_010000_add_customer_canonical_phone_and_merge_target.php');
    $migration->down();
    expect(Schema::hasColumn('pos_customers', 'phone_canonical'))->toBeFalse();
    expect(Schema::hasColumn('pos_customers', 'merged_into_customer_id'))->toBeFalse();
    $vectors = json_decode(file_get_contents(base_path('tests/Fixtures/canonical-phone-vectors.json')), true, flags: JSON_THROW_ON_ERROR);
    $expected = [];
    for ($i = 0; $i < 510; $i++) {
        // Unique raw strings with stable canonical forms, including deleted rows.
        $phone = $i < count($vectors) ? $vectors[$i][0] : '+968 '.(90000000 + $i);
        $id = DB::table('pos_customers')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $company->id,
            'name' => 'Migration synthetic '.$i, 'phone' => $phone,
            'deleted_at' => $i % 2 === 0 ? now() : null,
        ]);
        $expected[$id] = CanonicalPhone::of($phone);
    }
    $before = DB::table('pos_customers')->where('company_id', $company->id)->orderBy('id')->get();
    $migration->up();
    $after = DB::table('pos_customers')->where('company_id', $company->id)->orderBy('id')->get();
    expect($after)->toHaveCount(510);
    foreach ($after as $index => $row) {
        expect($row->phone_canonical)->toBe($expected[$row->id]);
        expect($row->merged_into_customer_id)->toBeNull();
        unset($row->phone_canonical, $row->merged_into_customer_id);
        expect((array) $row)->toBe((array) $before[$index]);
    }
    $migration->down();
    $migration->up();
    expect(DB::table('pos_customers')->where('company_id', $company->id)->orderBy('id')->pluck('phone_canonical', 'id')->all())->toBe($expected);
});
