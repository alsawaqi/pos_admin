<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing sliders — an ordered loop of approved advertiser content the admin
 * curates and pushes to devices. pos_admin OWNS these tables (the advertiser
 * content they reference lives in the marketing-api-owned content_assets).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_marketing_sliders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            // Seconds each item shows before advancing (per-item override lives
            // on the item row; this is the default + the loop cadence).
            $table->unsignedInteger('loop_interval_seconds')->default(8);
            $table->string('status')->default('draft')->index(); // draft | active | paused
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_marketing_sliders');
    }
};
