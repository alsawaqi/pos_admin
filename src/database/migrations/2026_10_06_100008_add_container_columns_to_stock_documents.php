<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH review add-on §3.1 / 08 — the container a stock document was
 * entered in (owner decisions D5, D6, tester call 10).
 *
 *   pos_purchase_receipt_lines  container_id (an ingredient's container) or
 *                               pack_id (a physical item's pack), with a
 *                               label and factor snapshot and the pieces
 *   pos_branch_transfer_line_containers  several containers per transfer
 *   pos_stock_count_line_containers      line / count line (one line per
 *                               item stays); they go with their line
 *   pos_waste_records,          container_id, pieces, container_label
 *   pos_restock_request_lines
 *
 * Every new column is NULL for existing rows (a document entered as an
 * amount). The label and factor are snapshots, so the container link is
 * emptied if the container itself is ever hard-deleted.
 */
return new class extends Migration
{
    private const CHILDREN = [
        'pos_branch_transfer_line_containers' => ['branch_transfer_line_id', 'pos_branch_transfer_lines'],
        'pos_stock_count_line_containers' => ['stock_count_line_id', 'pos_stock_count_lines'],
    ];

    public function up(): void
    {
        Schema::table('pos_purchase_receipt_lines', function (Blueprint $table): void {
            $table->foreignId('container_id')->nullable()->constrained('pos_ingredient_units')->nullOnDelete();
            $table->foreignId('pack_id')->nullable()->constrained('pos_product_packs')->nullOnDelete();
            $table->string('container_label', 80)->nullable();
            $table->decimal('container_factor', 14, 4)->nullable();
            $table->decimal('pieces', 14, 4)->nullable();
        });

        foreach (self::CHILDREN as $name => [$parentColumn, $parentTable]) {
            Schema::create($name, function (Blueprint $table) use ($name, $parentColumn, $parentTable): void {
                $table->id();
                $table->foreignId($parentColumn)->constrained($parentTable)->cascadeOnDelete();
                $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
                $table->foreignId('container_id')->nullable()->constrained('pos_ingredient_units')->nullOnDelete();
                $table->string('container_label', 80);
                $table->decimal('container_factor', 14, 4);
                $table->decimal('pieces', 14, 4);
                $table->timestamps();
                $table->index([$parentColumn], $name.'_line_idx');
            });
        }

        foreach (['pos_waste_records', 'pos_restock_request_lines'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('container_id')->nullable()->constrained('pos_ingredient_units')->nullOnDelete();
                $table->decimal('pieces', 14, 4)->nullable();
                $table->string('container_label', 80)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['pos_restock_request_lines', 'pos_waste_records'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropForeign(['container_id']);
                $table->dropColumn(['container_id', 'pieces', 'container_label']);
            });
        }

        foreach (array_reverse(array_keys(self::CHILDREN)) as $name) {
            Schema::dropIfExists($name);
        }

        Schema::table('pos_purchase_receipt_lines', function (Blueprint $table): void {
            $table->dropForeign(['container_id']);
            $table->dropForeign(['pack_id']);
            $table->dropColumn(['container_id', 'pack_id', 'container_label', 'container_factor', 'pieces']);
        });
    }
};
