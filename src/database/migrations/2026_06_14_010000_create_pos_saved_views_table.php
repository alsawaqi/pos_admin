<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved views — per-user filter presets for any portal screen.
 *
 * A "saved view" is a personal bookmark: a named bundle of filter values for
 * one screen (view_key, e.g. 'reports.sales', 'customers', 'restock-requests')
 * that the user can re-apply with one click. Personal, NOT shared — every row
 * is scoped to (company_id, user_id) and a user only ever sees their own.
 *
 *   - view_key   which screen the preset belongs to (free string the SPA owns).
 *   - filters    arbitrary JSON the screen interprets (date range, branch,
 *                status, etc.) — the backend stores it opaquely.
 *   - is_default at most one default per (user, view_key); applied on first
 *                load of that screen. Enforced in the application layer.
 *
 * Unique (user_id, view_key, name) so one user can't keep two presets with the
 * same name on the same screen; different users (and different screens) are
 * independent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_saved_views', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('pos_users')->cascadeOnDelete();
            $table->string('view_key', 64);
            $table->string('name', 100);
            // sqlite mirror stores text; production is jsonb on Postgres.
            $table->json('filters')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'view_key', 'name'], 'pos_saved_views_user_key_name_unique');
            $table->index(['user_id', 'view_key'], 'pos_saved_views_user_key_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_saved_views');
    }
};
