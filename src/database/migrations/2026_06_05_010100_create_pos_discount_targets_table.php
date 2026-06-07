<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6d — Discount targets (blueprint §10.7).
 *
 * Pivot from a discount to the entities it targets. Only
 * meaningful when discount.scope is 'product' or 'category';
 * 'order' scope discounts have no target rows (they apply
 * unconditionally).
 *
 * target_type catalogue:
 *   product   — target_id is a pos_products.id
 *   category  — target_id is a pos_product_categories.id
 *
 * No FK on target_id because the column is polymorphic across
 * two tables. The Action layer is the gatekeeper for tenant
 * consistency: it refuses to attach a target whose
 * (target_type, target_id) doesn't resolve in the actor's
 * company.
 *
 * Cascade on discount delete: when the parent discount is
 * hard-deleted, all targets vanish. Soft-deleting a discount
 * leaves them intact (the historical evaluator can still
 * resolve them via withTrashed()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_discount_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('discount_id')
                ->constrained('pos_discounts')
                ->cascadeOnDelete();

            // 32-char string. App enum
            // {@see App\Enums\DiscountTargetType}: product / category.
            $table->string('target_type', 32);
            $table->unsignedBigInteger('target_id');

            $table->timestamps();

            // Composite unique — a discount can't target the
            // same (type, id) twice.
            $table->unique(
                ['discount_id', 'target_type', 'target_id'],
                'pos_discount_targets_unique',
            );
            // Hot path: "is THIS product matched by ANY active
            // discount?" — POS sale write loop.
            $table->index(['target_type', 'target_id'], 'pos_discount_targets_type_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_discount_targets');
    }
};
