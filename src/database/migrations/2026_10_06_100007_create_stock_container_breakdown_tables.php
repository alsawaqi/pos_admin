<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH review add-on §3.1 / 07 — the stock breakdown by container (owner
 * decision D4, tester calls 7–9).
 *
 * pos_stock_container_balances: pieces of one LEAF container of an item at
 * one place. branch_id NULL = the company's central warehouse (the same
 * convention as pos_stock_movements). One row per place, item and
 * container: (branch_id, ingredient_id, container_id) for a branch and
 * (company_id, ingredient_id, container_id) for the warehouse (partial
 * unique indexes, Postgres and SQLite). pieces >= 0 (Postgres CHECK). The
 * breakdown is shown next to the live total and is NEVER used to compute
 * stock.
 *
 * pos_stock_container_movements: the append-only ledger of the breakdown
 * (balance = Σ delta_pieces), with the reason, the stock movement and the
 * document it belongs to, and who did it.
 *
 * pos_branch_stock.containers_counted_at / containers_total_count_at and
 * pos_ingredient_stock.containers_counted_at: when the breakdown was last
 * set by a count (or a warehouse correction), and when a device counted the
 * total only. NULL for every existing row; pos_api's firstOrCreate calls
 * are unaffected.
 */
return new class extends Migration
{
    public const REASONS = ['purchase', 'allocation_in', 'allocation_out', 'transfer_in', 'transfer_out', 'count',
        'correct', 'waste', 'clamp', 'device_count'];

    public function up(): void
    {
        Schema::create('pos_stock_container_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('pos_ingredients')->cascadeOnDelete();
            $table->foreignId('container_id')->constrained('pos_ingredient_units')->cascadeOnDelete();
            $table->decimal('pieces', 14, 4)->default(0);
            $table->timestamps();
            $table->index(['company_id', 'ingredient_id'], 'pos_stock_container_balances_company_ingredient_idx');
        });
        DB::statement('CREATE UNIQUE INDEX "pos_stock_container_balances_branch_unique" ON "pos_stock_container_balances" ("branch_id", "ingredient_id", "container_id") WHERE "branch_id" IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX "pos_stock_container_balances_warehouse_unique" ON "pos_stock_container_balances" ("company_id", "ingredient_id", "container_id") WHERE "branch_id" IS NULL');

        Schema::create('pos_stock_container_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('pos_ingredients')->cascadeOnDelete();
            $table->foreignId('container_id')->constrained('pos_ingredient_units')->cascadeOnDelete();
            $table->decimal('delta_pieces', 14, 4);
            $table->decimal('pieces_after', 14, 4);
            $table->string('reason', 32);
            $table->foreignId('stock_movement_id')->nullable()->constrained('pos_stock_movements')->nullOnDelete();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->foreignId('recorded_by_pos_staff_id')->nullable()->constrained('pos_staff')->nullOnDelete();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'ingredient_id', 'occurred_at'], 'pos_stock_container_movements_item_idx');
            $table->index(['branch_id', 'occurred_at'], 'pos_stock_container_movements_branch_idx');
            $table->index(['reference_type', 'reference_id'], 'pos_stock_container_movements_reference_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "pos_stock_container_balances" ADD CONSTRAINT "pos_stock_container_balances_pieces_check" CHECK ("pieces" >= 0)');
            DB::statement('ALTER TABLE "pos_stock_container_movements" ADD CONSTRAINT "pos_stock_container_movements_reason_check" CHECK ("reason" IN (\''.implode("', '", self::REASONS).'\'))');
            DB::statement('ALTER TABLE "pos_stock_container_movements" ADD CONSTRAINT "pos_stock_container_movements_pieces_after_check" CHECK ("pieces_after" >= 0)');
        }

        Schema::table('pos_branch_stock', function (Blueprint $table): void {
            $table->timestamp('containers_counted_at')->nullable();
            $table->timestamp('containers_total_count_at')->nullable();
        });
        Schema::table('pos_ingredient_stock', function (Blueprint $table): void {
            $table->timestamp('containers_counted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pos_ingredient_stock', function (Blueprint $table): void {
            $table->dropColumn('containers_counted_at');
        });
        Schema::table('pos_branch_stock', function (Blueprint $table): void {
            $table->dropColumn(['containers_counted_at', 'containers_total_count_at']);
        });
        Schema::dropIfExists('pos_stock_container_movements');
        Schema::dropIfExists('pos_stock_container_balances');
    }
};
