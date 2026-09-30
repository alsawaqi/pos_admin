<?php

use App\Enums\PlatformRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Device;
use App\Models\User;
use App\Services\P0SyncHistoryRepair;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * A device sold offline for merchant A, then moved to merchant B. Its unsent
 * A sale arrives after the move and the API stores it (at push time) as
 * needs_review. Returns the ids the review must reason about.
 */
function fix3MovedDeviceWithRefusedSale(?Closure $claimed = null, string $madeAt = '-2 days'): array
{
    $a = Company::factory()->create();
    $aBranch = Branch::factory()->for($a)->create();
    $b = Company::factory()->create();
    $bBranch = Branch::factory()->for($b)->create();
    $device = Device::factory()->create(['company_id' => $b->id, 'branch_id' => $bBranch->id,
        'status' => 'active', 'assigned_at' => now()->subDay()]);
    $aHistory = DB::table('pos_device_assignments_history')->insertGetId(['device_id' => $device->id,
        'company_id' => $a->id, 'branch_id' => $aBranch->id, 'assigned_at' => now()->subDays(3),
        'unassigned_at' => now()->subDay(), 'created_at' => now()]);
    $bHistory = DB::table('pos_device_assignments_history')->insertGetId(['device_id' => $device->id,
        'company_id' => $b->id, 'branch_id' => $bBranch->id, 'assigned_at' => now()->subDay(),
        'created_at' => now()]);
    $result = ['error' => 'identity_mismatch', 'code' => 'identity_mismatch', 'permanent' => true];
    if ($claimed !== null) {
        $result['claimed_identity'] = $claimed($a, $aBranch, $b, $bBranch);
    }
    $event = DB::table('pos_sync_events')->insertGetId([
        'device_id' => $device->id, 'client_event_id' => Str::uuid(), 'event_type' => 'expense.log',
        'payload_json' => '{"amount_baisas":100}', 'client_timestamp' => now()->modify($madeAt),
        'server_received_at' => now(), 'ack_status' => 'needs_review',
        'result_json' => json_encode($result),
    ]);

    return compact('a', 'aBranch', 'b', 'bBranch', 'device', 'aHistory', 'bHistory', 'event');
}

function fix3Attribute($test, int $event, User $actor, Company $company, Branch $branch)
{
    $file = tempnam(sys_get_temp_dir(), 'p0-fix3-');
    file_put_contents($file, json_encode(['company_id' => $company->id, 'branch_id' => $branch->id,
        'bank_id' => null, 'terminal_id' => null, 'commission_profile_id' => null,
        'organization_id' => null, 'device_type' => 'pos_terminal']));
    try {
        return $test->artisan('pos:repair-sync-history', ['operation' => 'attribute', '--event' => $event,
            '--file' => $file, '--actor' => $actor->id, '--reason' => 'Original assignment evidence']);
    } finally {
        register_shutdown_function(fn () => @unlink($file));
    }
}

beforeEach(function () {
    $this->seed(PlatformRoleSeeder::class);
    $this->actor = User::factory()->create();
    $this->actor->assignRole(PlatformRole::SuperAdmin->value);
});

it('D4 lists a refused sale against the assignment in force when it was made, never today\'s merchant', function () {
    $s = fix3MovedDeviceWithRefusedSale();

    expect(Artisan::call('pos:repair-sync-history', ['operation' => 'list']))->toBe(0);
    $listed = json_decode(trim(Artisan::output()), true);

    expect($listed['id'])->toBe($s['event'])
        ->and($listed['refused_at_ingest'])->toBeTrue()
        ->and($listed['assignment_at_evidence_time'])->toBe($s['aHistory'])
        ->and($listed['continuous_assignment'])->toBeNull();
});

it('D4 refuses to attribute a refused sale to the merchant the device moved to, and accepts the original one', function () {
    $s = fix3MovedDeviceWithRefusedSale();

    fix3Attribute($this, $s['event'], $this->actor, $s['b'], $s['bBranch'])->assertFailed();
    expect(DB::table('pos_sync_event_reviews')->count())->toBe(0);

    fix3Attribute($this, $s['event'], $this->actor, $s['a'], $s['aBranch'])->assertSuccessful();
    $this->assertDatabaseHas('pos_sync_event_reviews', ['sync_event_id' => $s['event'],
        'company_id' => $s['a']->id, 'branch_id' => $s['aBranch']->id]);
    $this->assertDatabaseHas('pos_sync_events', ['id' => $s['event'], 'company_id' => $s['a']->id,
        'ack_status' => 'needs_review']);
});

it('D4 a tagged refused sale is attributed only to the identity the device stamped on it', function () {
    // Device clock ran behind: by its timestamp the sale falls in B's
    // assignment, but the tag it carried proves merchant A.
    $s = fix3MovedDeviceWithRefusedSale(
        fn ($a, $aBranch) => ['company_id' => $a->id, 'branch_id' => $aBranch->id, 'device_uuid' => null],
        '-2 hours',
    );

    fix3Attribute($this, $s['event'], $this->actor, $s['b'], $s['bBranch'])->assertFailed();
    fix3Attribute($this, $s['event'], $this->actor, $s['a'], $s['aBranch'])->assertSuccessful();
    $this->assertDatabaseHas('pos_sync_event_reviews', ['sync_event_id' => $s['event'], 'company_id' => $s['a']->id]);
});

it('D4 a tag naming a merchant the device never belonged to attributes nowhere', function () {
    // A device of merchant B stamps merchant C on a sale: the tag alone must
    // not let a review settle it under C (nor under B, which it contradicts).
    $c = Company::factory()->create();
    $cBranch = Branch::factory()->for($c)->create();
    $s = fix3MovedDeviceWithRefusedSale(
        fn () => ['company_id' => $c->id, 'branch_id' => $cBranch->id, 'device_uuid' => null],
        '-2 hours',
    );

    fix3Attribute($this, $s['event'], $this->actor, $c, $cBranch)->assertFailed();
    fix3Attribute($this, $s['event'], $this->actor, $s['b'], $s['bBranch'])->assertFailed();
    expect(DB::table('pos_sync_event_reviews')->count())->toBe(0);
});

it('D4 automatic repair leaves refused sales in review', function () {
    $s = fix3MovedDeviceWithRefusedSale();

    app(P0SyncHistoryRepair::class)->repair();

    $this->assertDatabaseHas('pos_sync_events', ['id' => $s['event'], 'company_id' => null,
        'ack_status' => 'needs_review']);
});
