<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P-F7 — "already forwarded to charity" marker on round-up donations.
 *
 * A round-up that rides a force-recorded (pending_reconciliation) card
 * charge must NOT reach the charity app until the platform admin confirms
 * the money against the bank file. pos_api's DonationRecordHandler now
 * stamps forwarded_at only after a SUCCESSFUL forward (and skips the
 * forward entirely while the order has a pending tender); the admin
 * reconciliation paths (approval queue + bank-file commit) forward any
 * row whose marker is still NULL and stamp it then.
 *
 * Mirrored in pos_api's test schema (0000_00_00_000000_create_test_schema).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_roundup_donations', function (Blueprint $table): void {
            $table->timestamp('forwarded_at')->nullable()->after('occurred_at');
        });

        // Backfill: every pre-existing row was written under the old
        // forward-at-record-time behaviour, so treat history as already
        // forwarded — otherwise the first bank-file commit touching an old
        // order would re-forward (duplicate) its donation at the charity.
        DB::table('pos_roundup_donations')->update(['forwarded_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('pos_roundup_donations', function (Blueprint $table): void {
            $table->dropColumn('forwarded_at');
        });
    }
};
