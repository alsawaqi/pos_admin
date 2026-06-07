<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conditionally create a `regions` stub (charity-owned in prod).
 * Mirrors {@see 2026_05_26_010000_ensure_banks_stub.php}.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('regions')) {
            return;
        }

        Schema::create('regions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 50)->nullable();
            $table->string('code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['country_id', 'name'], 'regions_unique_name_per_country');
        });
    }

    public function down(): void
    {
        // No-op: the charity app owns this table in production.
    }
};
