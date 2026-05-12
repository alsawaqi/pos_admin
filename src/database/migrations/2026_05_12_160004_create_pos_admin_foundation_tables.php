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
        Schema::create('pos_admin_branches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_admin_companies')->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('manager_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->unsignedBigInteger('country_id')->nullable()->index('pos_admin_branches_country_id_index');
            $table->unsignedBigInteger('region_id')->nullable()->index('pos_admin_branches_region_id_index');
            $table->unsignedBigInteger('district_id')->nullable()->index('pos_admin_branches_district_id_index');
            $table->unsignedBigInteger('city_id')->nullable()->index('pos_admin_branches_city_id_index');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('status')->default('active')->index();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code'], 'pos_admin_branches_company_code_unique');
            $table->index(['company_id', 'status'], 'pos_admin_branches_company_status_index');
        });

        Schema::create('pos_admin_branch_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('pos_admin_companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('pos_admin_branches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('pos_admin_users')->cascadeOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('pos_admin_users')->nullOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['branch_id', 'user_id'], 'pos_admin_branch_user_branch_user_unique');
            $table->index(['company_id', 'user_id'], 'pos_admin_branch_user_company_user_index');
        });

        Schema::create('pos_admin_devices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('serial_number')->unique();
            $table->string('name')->nullable();
            $table->string('device_type')->default('pos_terminal');
            $table->foreignId('company_id')->nullable()->constrained('pos_admin_companies')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('pos_admin_branches')->nullOnDelete();
            $table->foreignId('registered_by_user_id')->nullable()->constrained('pos_admin_users')->nullOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('pos_admin_users')->nullOnDelete();
            $table->string('status')->default('registered')->index();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->string('last_ip', 45)->nullable();
            $table->string('app_version')->nullable();
            $table->string('firmware_version')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'branch_id', 'status'], 'pos_admin_devices_assignment_status_index');
        });

        Schema::create('pos_admin_device_activation_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained('pos_admin_devices')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('created_by_user_id')->nullable()->constrained('pos_admin_users')->nullOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'used_at', 'revoked_at'], 'pos_admin_activation_tokens_usage_index');
        });

        Schema::create('pos_admin_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('pos_admin_users')->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('pos_admin_companies')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('pos_admin_branches')->nullOnDelete();
            $table->string('event')->index();
            $table->nullableMorphs('auditable', 'pos_admin_audit_logs_auditable_index');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'event'], 'pos_admin_audit_logs_company_event_index');
            $table->index(['actor_user_id', 'created_at'], 'pos_admin_audit_logs_actor_created_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_admin_audit_logs');
        Schema::dropIfExists('pos_admin_device_activation_tokens');
        Schema::dropIfExists('pos_admin_devices');
        Schema::dropIfExists('pos_admin_branch_user');
        Schema::dropIfExists('pos_admin_branches');
    }
};
