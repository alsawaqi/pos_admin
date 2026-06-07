<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conditionally create a `districts` stub (charity-owned in prod).
 * Mirrors {@see 2026_05_26_010000_ensure_banks_stub.php}.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('districts')) {
            return;
        }

        Schema::create('districts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('region_id')->constrained('regions')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // No-op: the charity app owns this table in production.
    }
};
