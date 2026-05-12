<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
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

        Schema::create('pos_admin_password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('pos_admin_sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index()->constrained('pos_admin_users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_admin_sessions');
        Schema::dropIfExists('pos_admin_password_reset_tokens');
        Schema::dropIfExists('pos_admin_users');
    }
};
