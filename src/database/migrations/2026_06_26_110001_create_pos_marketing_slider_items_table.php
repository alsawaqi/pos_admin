<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ordered items of a slider. Each references a content_asset (cross-app,
 * marketing-api-owned → no DB FK) and snapshots advertiser_id for fast grouping
 * + competitor checks without re-joining the shared table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_marketing_slider_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('slider_id')->constrained('pos_marketing_sliders')->cascadeOnDelete();
            $table->unsignedBigInteger('content_asset_id')->index();
            $table->unsignedBigInteger('advertiser_id')->nullable()->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('duration_seconds')->default(8);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_marketing_slider_items');
    }
};
