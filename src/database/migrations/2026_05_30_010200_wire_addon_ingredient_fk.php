<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5b — wire the deferred FK on pos_addons.ingredient_id.
 *
 * Phase 4.9 created pos_addons.ingredient_id as a nullable
 * unsignedBigInteger WITHOUT a FK constraint, because
 * pos_ingredients didn't exist yet. Phase 5a created
 * pos_ingredients, so now we can complete the relationship.
 *
 * ingredient_id stays nullable — most add-ons (sugar level,
 * service type) don't consume ingredients. nullOnDelete keeps
 * the add-on alive when the merchant retires the underlying
 * ingredient (the next sale of that add-on simply won't
 * deduct anything — better than orphaning the add-on row).
 *
 * Postgres is fine to add FK to a nullable column even when
 * existing rows reference NULLs (NULL passes referential
 * integrity by definition). No backfill needed.
 *
 * SQLite (test mirror) doesn't enforce FKs by default but
 * accepts the column shape; the test-schema migration is
 * updated separately to declare the constrained() call too
 * so model relations resolve correctly in feature tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_addons', function (Blueprint $table): void {
            $table->foreign('ingredient_id', 'pos_addons_ingredient_id_foreign')
                ->references('id')
                ->on('pos_ingredients')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_addons', function (Blueprint $table): void {
            $table->dropForeign('pos_addons_ingredient_id_foreign');
        });
    }
};
