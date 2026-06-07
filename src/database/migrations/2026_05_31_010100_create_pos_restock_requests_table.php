<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5c — Restock requests, header table (blueprint §5.6.5).
 *
 * A branch's request to be restocked. The flow:
 *
 *   draft   — created but not yet sent for review. Editable.
 *     ↓ (Submit)
 *   submitted — locked, awaiting HQ review.
 *     ↓ (Approve)            ↓ (Reject)
 *   approved                  rejected (terminal)
 *     ↓ (Allocate)
 *   fulfilled (terminal)
 *
 *   draft or submitted → cancelled (terminal) by the requester
 *
 * Allocation writes stock_movements at the requesting branch
 * with type=restock and reference_type=RestockRequest. Per-line
 * quantity_allocated tracks the partial-fulfillment case where
 * HQ approves but only sends some of the requested amount —
 * the request still ends at status=fulfilled and the gap is
 * visible on the line.
 *
 * company_id is denormalised from the branch for fast tenant
 * scoping ("show all requests for my company across all my
 * branches") without an extra join.
 *
 * Why no soft delete: a cancelled request stays in the table
 * with status=cancelled — it's part of the audit trail. The
 * UI just filters it out by default.
 *
 * Columns:
 *   status            — closed enum, see flow diagram above.
 *   submitted_at      — set when status moves draft → submitted.
 *                        Useful for reporting on time-to-review.
 *   reviewed_by/at    — set when status moves submitted →
 *                        {approved, rejected}.
 *   review_note       — required on reject ("we're out of beans
 *                        too"), optional on approve.
 *   fulfilled_at      — set when status moves approved →
 *                        fulfilled (allocation written).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_restock_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // Denormalised from the branch — see class doc.
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->foreignId('branch_id')
                ->constrained('pos_branches')
                ->cascadeOnDelete();
            // 32-char enum: draft / submitted / approved /
            // fulfilled / rejected / cancelled. Plenty of headroom.
            $table->string('status', 32)->default('draft');
            $table->foreignId('requested_by_user_id')
                ->nullable()
                ->constrained('pos_users')
                ->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by_user_id')
                ->nullable()
                ->constrained('pos_users')
                ->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            // Hot paths: "all requests for my company" + "requests
            // pending review" + "branch's request history".
            $table->index(['company_id', 'status'], 'pos_restock_requests_company_status_idx');
            $table->index(['branch_id', 'created_at'], 'pos_restock_requests_branch_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_restock_requests');
    }
};
