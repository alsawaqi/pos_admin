<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QR-003 T1 — unpaid QR references, separate from official receipt numbers.
 *
 * pos_admin owns this schema; pos_api allocates the branch/day counter.
 * Existing orders are not backfilled. The reference is nullable and indexed,
 * not unique: the human-readable label is not a permanent order identifier.
 * The counter is independent of devices and merchant receipt-number settings.
 * All scope columns are required, so a plain unique constraint is sufficient.
 * next_number is the number returned by the next allocation, starting at 1.
 *
 * T2 must reuse pos_temp_reference_sequences rather than introduce a second
 * pos_table_session_sequences table for the same branch/day reference purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->string('temp_reference', 32)->nullable()->after('receipt_number');
            $table->index(['branch_id', 'temp_reference'], 'pos_orders_branch_temp_reference_idx');
        });

        Schema::create('pos_temp_reference_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('pos_companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->date('seq_date');
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique(['company_id', 'branch_id', 'seq_date'], 'pos_temp_reference_sequences_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_temp_reference_sequences');

        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropIndex('pos_orders_branch_temp_reference_idx');
            $table->dropColumn('temp_reference');
        });
    }
};
