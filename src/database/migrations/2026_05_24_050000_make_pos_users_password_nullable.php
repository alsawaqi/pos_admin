<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Make `pos_users.password` nullable.
 *
 * Why: merchant portal users created by the admin's "Invite portal
 * user" flow (blueprint §4.5) have NO password until the recipient
 * clicks the welcome-email link and sets one. We previously
 * persisted the empty-string default to satisfy the NOT NULL
 * constraint, but that's a weak credential we don't want stored at
 * any moment — better to keep the column NULL until the user
 * completes setup.
 *
 * Admin staff (user_type=platform_admin) always have a password
 * set, so this loosening doesn't affect their flow. The relaxation
 * only matters for invite-pending portal users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_users', function (Blueprint $table): void {
            // Postgres + MySQL both need ->change() to flip the
            // nullability of an existing column. The doctrine/dbal
            // package is required by Laravel for ->change() to work
            // — already shipping in the framework's dev deps.
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Restoring NOT NULL is risky if any rows have a NULL
        // password (which is exactly the state portal users sit in
        // until setup completes). The reverse is intentionally a
        // best-effort: rows that have null get a random hash so the
        // constraint can be re-applied. They will still need to use
        // the regular password-reset flow to recover access.
        DB::table('pos_users')
            ->whereNull('password')
            ->update([
                'password' => bcrypt(Str::random(32)),
            ]);

        Schema::table('pos_users', function (Blueprint $table): void {
            $table->string('password')->nullable(false)->change();
        });
    }
};
