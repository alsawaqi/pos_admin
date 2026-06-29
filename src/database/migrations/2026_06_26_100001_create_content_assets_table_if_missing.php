<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `content_assets` is OWNED by the marketing-api app (shared charity_db).
 * pos_admin reads it for content review + writes only the review fields
 * (status / review_note / reviewed_at). In production this is a NO-OP (the
 * table already exists). It exists so pos_admin's isolated sqlite test DB has a
 * permissive stub — `status` is a plain string (no CHECK) so the test can move
 * an asset to 'rejected'. Mirrors the columns pos_admin reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('content_assets')) {
            return;
        }

        Schema::create('content_assets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('advertiser_id')->nullable()->index();
            $table->string('title', 200);
            $table->string('type')->index();          // image | video
            $table->string('status')->default('draft')->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('disk', 50)->default('public');
            $table->string('path')->nullable();
            $table->string('url')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->text('description')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        // Never drop a table this app does not own.
    }
};
