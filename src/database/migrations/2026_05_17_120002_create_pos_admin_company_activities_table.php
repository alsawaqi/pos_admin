<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_admin_company_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('pos_admin_companies')->cascadeOnDelete();
            $table->foreignId('business_activity_id')->constrained('pos_admin_business_activities')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'business_activity_id'], 'pos_admin_company_activity_unique');
            $table->index(['company_id', 'is_primary'], 'pos_admin_company_activity_primary_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_admin_company_activities');
    }
};
