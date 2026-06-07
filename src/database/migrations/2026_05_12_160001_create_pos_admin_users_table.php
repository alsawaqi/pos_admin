<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS admin users.
 *
 * Distinct from the charity `users` table — different domain (platform
 * staff vs charity organisation users), different columns (company_id,
 * user_type, status, phone, timezone, locale, last_login_at, metadata),
 * and referenced by ~10 foreign keys across the pos_admin_* schema.
 *
 * Sessions and password reset tokens are NOT created here; the SPA reuses
 * the charity DB's existing `sessions` and `password_reset_tokens` tables
 * (Laravel defaults) — we just point our session/auth config at them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_admin_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('pos_admin_companies')->restrictOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable()->index();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('user_type')->default('merchant')->index();
            $table->string('status')->default('active')->index();
            $table->rememberToken();
            $table->timestamp('last_login_at')->nullable();
            $table->string('timezone')->default('Asia/Muscat');
            $table->string('locale', 10)->default('en');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_admin_users');
    }
};
