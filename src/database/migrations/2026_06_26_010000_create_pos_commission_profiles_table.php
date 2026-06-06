<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS-owned, per-merchant commission profile.
 *
 * Distinct from the charity-owned `commission_profiles` reference table
 * (which POS only reads, has NO rate columns, and drives the round-up
 * donation snapshot). THIS table is the platform's per-merchant revenue
 * split: when the admin onboards/manages a merchant they configure how
 * every sale is divided between the platform, the acquiring bank, any
 * other parties, and the merchant.
 *
 * One profile per merchant (company_id unique). The split lines live in
 * pos_commission_shares; the merchant always takes the RESIDUAL
 * (100 - Σ shares), stored here as merchant_percent for convenience and
 * so the per-sale ledger can snapshot it without re-deriving.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_commission_profiles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // One profile per merchant. Cascade so deleting a company
            // (hard delete) takes its profile + shares with it.
            $table->unsignedBigInteger('company_id')->unique();
            $table->foreign('company_id')->references('id')->on('pos_companies')->cascadeOnDelete();

            $table->boolean('is_active')->default(true);

            // Residual the merchant keeps = 100 - Σ(share percents).
            // Stored (not just derived) so the sale ledger can snapshot it.
            $table->decimal('merchant_percent', 5, 2)->default(100);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_commission_profiles');
    }
};
