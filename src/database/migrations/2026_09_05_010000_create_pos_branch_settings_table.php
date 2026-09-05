<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema owned by pos_admin, rows written by pos_merchant, read by pos_api.
 *
 * company_id is denormalised for tenant scoping and the company/key index.
 * The merchant write action, not the database, enforces that the branch
 * belongs to that company. value is JSON: dine_in_round_mode stores a JSON
 * string such as "staff_confirm", not an object or an unencoded string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_branch_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->string('key', 64);
            $table->json('value')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'key'], 'pos_branch_settings_branch_key_unique');
            $table->index(['company_id', 'key'], 'pos_branch_settings_company_key_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_branch_settings');
    }
};
