<?php

declare(strict_types=1);

/**
 * Sprint 3 — application-layer encryption on PII columns
 * (blueprint §9.13.2).
 *
 * What we want to prove:
 *   1. When the model saves, the value lands in the DB encrypted
 *      (i.e. a raw query via DB::table returns ciphertext, NOT the
 *      plaintext we passed in).
 *   2. When the model loads, the value comes back as plaintext —
 *      the application code never sees ciphertext.
 *   3. Nullable columns stay null on save + on read (no accidental
 *      encryption of empty values that would break the storage
 *      contract).
 *
 * Covered columns:
 *   - pos_company_owners.civil_id / phone / email
 *   - pos_users.phone
 *
 * Email on pos_users is intentionally NOT encrypted — see the
 * comment in User::casts() (it's the login key, queried by
 * authentication paths).
 */

use App\Models\Company;
use App\Models\CompanyOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('stores company owner PII as ciphertext at rest', function (): void {
    $company = Company::factory()->create();

    $owner = CompanyOwner::factory()
        ->for($company)
        ->create([
            'civil_id' => '1234567890',
            'phone' => '+96812345678',
            'email' => 'owner@example.com',
        ]);

    // 1. Eloquent reads back the plaintext.
    expect($owner->civil_id)->toBe('1234567890');
    expect($owner->phone)->toBe('+96812345678');
    expect($owner->email)->toBe('owner@example.com');

    // 2. Raw query bypasses the cast — what's actually in the
    //    column should NOT be the plaintext.
    $raw = DB::table('pos_company_owners')->where('id', $owner->id)->first();
    expect($raw->civil_id)->not->toBe('1234567890');
    expect($raw->phone)->not->toBe('+96812345678');
    expect($raw->email)->not->toBe('owner@example.com');

    // 3. Laravel's encrypted payload is always a non-empty string;
    //    we don't pin the exact format because it includes a random
    //    IV per encrypt.
    expect($raw->civil_id)->toBeString()->not->toBeEmpty();
    expect($raw->phone)->toBeString()->not->toBeEmpty();
    expect($raw->email)->toBeString()->not->toBeEmpty();
});

it('stores user phone as ciphertext at rest', function (): void {
    $user = User::factory()->create([
        'phone' => '+96899887766',
    ]);

    expect($user->phone)->toBe('+96899887766');

    $raw = DB::table('pos_users')->where('id', $user->id)->first();
    expect($raw->phone)->not->toBe('+96899887766');
    expect($raw->phone)->toBeString()->not->toBeEmpty();
});

it('keeps null PII columns null on save + read', function (): void {
    // Sanity check: empty / null values must not get "encrypted" into
    // a non-null ciphertext (would silently break "is this owner
    // missing contact info" checks).
    $company = Company::factory()->create();

    $owner = CompanyOwner::factory()
        ->for($company)
        ->create([
            'civil_id' => null,
            'phone' => null,
            'email' => null,
        ]);

    expect($owner->civil_id)->toBeNull();
    expect($owner->phone)->toBeNull();
    expect($owner->email)->toBeNull();

    $raw = DB::table('pos_company_owners')->where('id', $owner->id)->first();
    expect($raw->civil_id)->toBeNull();
    expect($raw->phone)->toBeNull();
    expect($raw->email)->toBeNull();
});

it('does NOT encrypt the user email (it is the login key)', function (): void {
    // Email lookup by raw value must still work — the authentication
    // path queries pos_users.email = ? at login. If this test ever
    // starts failing because someone added 'email' to User::casts(),
    // do not "fix" the test — fix the model: encrypting email would
    // break login.
    $user = User::factory()->create(['email' => 'admin@example.com']);

    $raw = DB::table('pos_users')->where('id', $user->id)->first();
    expect($raw->email)->toBe('admin@example.com');
});
