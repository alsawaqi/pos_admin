<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Force a self-chosen password on first login. The platform admin
 * creates a merchant owner with a temporary password (shared out of
 * band); this flag makes pos_merchant force the user to set their own
 * password before they can use the portal. Cleared when they change it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_users', function (Blueprint $table): void {
            $table->boolean('must_change_password')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('pos_users', function (Blueprint $table): void {
            $table->dropColumn('must_change_password');
        });
    }
};
