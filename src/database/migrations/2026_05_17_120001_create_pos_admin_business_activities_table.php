<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_admin_business_activities', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('category', 64)->index();
            $table->string('isic_code', 16)->nullable()->index();
            $table->text('description_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_admin_business_activities');
    }
};
