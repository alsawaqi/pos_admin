<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branch transfers — move stock from one branch to another (§5.6).
 *
 * A transfer is an IMMEDIATE, atomic physical move: each line writes a paired
 * transfer_out movement at the source branch and a transfer_in at the
 * destination, so the existing stock ledger stays the single source of truth
 * (SUM(movements) == branch_stock per branch+ingredient). There is no
 * approval workflow — recording the transfer IS the act of moving the stock.
 *
 * Header carries from/to branches + who/when; lines carry the per-ingredient
 * quantity and a unit snapshot. company_id is denormalised on both so the
 * tenant scope never needs a join through branches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_branch_transfers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('from_branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('to_branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('transferred_by_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->timestamp('transferred_at')->useCurrent();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'transferred_at'], 'pos_branch_transfers_company_time_idx');
            $table->index(['from_branch_id', 'transferred_at'], 'pos_branch_transfers_from_time_idx');
            $table->index(['to_branch_id', 'transferred_at'], 'pos_branch_transfers_to_time_idx');
        });

        Schema::create('pos_branch_transfer_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_transfer_id')->constrained('pos_branch_transfers')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('pos_ingredients')->cascadeOnDelete();
            $table->decimal('quantity', 12, 3);
            // Unit snapshot at transfer time (the ingredient's unit may later
            // change; the historical line should read as it was sent).
            $table->string('unit_at_set', 16);
            // Source unit cost at transfer time → the transfer_out / transfer_in
            // movements carry it so COGS stays consistent across the move.
            $table->decimal('unit_cost_at_time', 12, 3)->default(0);
            $table->timestamps();

            $table->unique(['branch_transfer_id', 'ingredient_id'], 'pos_branch_transfer_lines_transfer_ingredient_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_branch_transfer_lines');
        Schema::dropIfExists('pos_branch_transfers');
    }
};
