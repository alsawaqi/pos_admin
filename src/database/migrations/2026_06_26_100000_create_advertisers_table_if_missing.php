<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `advertisers` table is OWNED by the marketing-api app — it lives in the
 * shared charity_db and marketing-api created it. pos_admin only reads + writes
 * advertiser rows for admin-driven onboarding (create the account, link it to a
 * merchant company, suspend, reset password).
 *
 * In production this migration is a NO-OP: the table already exists, so the
 * guard short-circuits. It exists purely so pos_admin's isolated test database
 * (sqlite) — which runs only pos_admin migrations — has the table available,
 * mirroring the marketing-api schema + the admin-onboarding columns
 * (company_id / is_merchant / category).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('advertisers')) {
            return;
        }

        Schema::create('advertisers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('brand_name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('phone')->nullable();
            $table->string('status')->default('active')->index();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->boolean('is_merchant')->default(false);
            $table->string('category')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        // Never drop a table this app does not own.
    }
};
