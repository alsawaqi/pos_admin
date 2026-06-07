<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_admin_company_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('pos_admin_companies')->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('changed_by_user_id')->nullable()->constrained('pos_admin_users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('changed_at')->useCurrent()->index();

            $table->index(['company_id', 'changed_at'], 'pos_admin_company_status_history_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_admin_company_status_history');
    }
};
