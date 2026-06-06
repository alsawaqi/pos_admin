<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The non-merchant split lines of a per-merchant commission profile.
 *
 * Each row is a party that takes a cut of every sale: the platform
 * ("how much I take"), the acquiring bank, or any other configured
 * party. The merchant is NOT a row here — it takes the residual
 * (pos_commission_profiles.merchant_percent = 100 - Σ percents). The
 * admin form blocks Σ percents > 100.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_commission_shares', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('commission_profile_id');
            $table->foreign('commission_profile_id')
                ->references('id')->on('pos_commission_profiles')
                ->cascadeOnDelete();

            // platform | bank | other (see App\Enums\CommissionPartyType).
            $table->string('party_type', 20);
            $table->string('label', 120);
            $table->decimal('percent', 5, 2);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['commission_profile_id', 'sort_order'], 'pos_commission_shares_profile_sort_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_commission_shares');
    }
};
