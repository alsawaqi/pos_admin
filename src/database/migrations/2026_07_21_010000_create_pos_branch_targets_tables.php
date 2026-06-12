<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P-G8 — branch performance targets.
 *
 * The merchant sets a sales goal per branch — an amount per day / week /
 * month plus an evaluation window of N periods (200/day over a 3-day
 * window means the window's goal is 600 CUMULATIVE: a weak day can be
 * saved by a strong one). Windows run back-to-back from starts_on.
 *
 * pos_branch_targets — the target definition (config). One ACTIVE target
 * per branch (enforced by the merchant Action layer, not the DB — a
 * replaced target is deactivated and keeps its window history).
 *
 * pos_branch_target_windows — one row per FINISHED window: the goal
 * frozen at finalization (amount may be edited later; history must not
 * rewrite), the actual confirmed sales, and the hit/miss verdict. There
 * is no scheduler in this stack: windows are finalized LAZILY when the
 * portal reads targets/dashboard, idempotent via the (target,
 * window_start) unique. "Sales" = confirmed money only — paid orders
 * excluding any with a tender still pending reconciliation; F7's
 * confirmed deliveries already re-date opened_at to the confirmation
 * moment, so they land in the window the money arrived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_branch_targets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->string('period', 16); // day | week | month (PHP-enum gated)
            $table->decimal('amount', 12, 3); // the goal per single period
            $table->unsignedSmallInteger('window_periods')->default(1); // N periods per window
            $table->date('starts_on'); // windows run back-to-back from here
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('pos_users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'branch_id', 'is_active'], 'pos_branch_targets_company_branch_active_idx');
        });

        Schema::create('pos_branch_target_windows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('target_id')->constrained('pos_branch_targets')->cascadeOnDelete();
            // Denormalised for scope-filtered history queries without joins
            // (the pos_roundup_donations cross-concern precedent).
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('branch_id')->index();
            $table->date('window_start');
            $table->date('window_end'); // inclusive last day of the window
            $table->decimal('goal_amount', 12, 3); // amount x window_periods, frozen
            $table->decimal('actual_amount', 12, 3); // confirmed sales in the window
            $table->boolean('hit');
            $table->timestamp('finalized_at');
            $table->timestamps();
            $table->unique(['target_id', 'window_start'], 'pos_branch_target_windows_target_start_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_branch_target_windows');
        Schema::dropIfExists('pos_branch_targets');
    }
};
