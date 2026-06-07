<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conditionally create a `cities` stub (charity-owned in prod).
 * Mirrors {@see 2026_05_26_010000_ensure_banks_stub.php}.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cities')) {
            return;
        }

        Schema::create('cities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('region_id')->constrained('regions')->cascadeOnDelete();
            $table->foreignId('district_id')->nullable()->constrained('districts')->nullOnDelete();
            $table->string('name');
            $table->string('postal_code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['region_id', 'name'], 'cities_unique_name_per_region');
        });
    }

    public function down(): void
    {
        // No-op: the charity app owns this table in production.
    }
};
