<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Advertising play-time + audience telemetry. One row per slide shown on a
 * device's customer screen. pos_admin OWNS the DDL (the pos_* schema in
 * charity_db); pos_api WRITES rows from `slider.display` sync events
 * (App\Actions\Device\Sync\Handlers\SliderDisplayHandler) and pos_api mirrors
 * this slice in its test-only schema.
 *
 * Audience columns are ANONYMOUS, AGGREGATE counts only — no images or
 * identities are ever stored. NULL = audience measurement was off for that
 * play (distinct from 0 = measured, nobody watching). FK columns are kept
 * plain (no ->constrained) because they reference cross-owned / cross-app
 * tables; the unique (device_id, client_event_id) is the replay guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_marketing_impressions', function (Blueprint $table): void {
            $table->id();

            // Reporting device + its scope.
            $table->unsignedBigInteger('device_id')->nullable()->index();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable()->index();

            // What played.
            $table->unsignedBigInteger('slider_id')->nullable()->index();
            $table->unsignedBigInteger('slider_item_id')->nullable();
            $table->unsignedBigInteger('content_asset_id')->nullable();
            $table->unsignedBigInteger('advertiser_id')->nullable()->index();
            $table->unsignedInteger('play_duration_ms')->default(0);

            // Anonymous audience measurement (camera + on-device face detection).
            // NULL = not measured on this play.
            $table->unsignedInteger('viewers_peak')->nullable();    // max concurrent faces
            $table->unsignedInteger('viewers_avg')->nullable();     // avg concurrent faces
            $table->unsignedInteger('viewers_distinct')->nullable(); // distinct people (OTS)
            $table->unsignedInteger('attention_ms')->nullable();    // face-toward-screen time

            // Idempotency / replay guard for the sync pipeline.
            $table->uuid('client_event_id')->nullable();
            $table->timestamp('played_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['device_id', 'client_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_marketing_impressions');
    }
};
