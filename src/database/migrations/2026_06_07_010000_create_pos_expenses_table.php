<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 backfill — Expenses (blueprint §5.10 + §10.8).
 *
 * "Captured from POS": POS staff log on-the-spot expenses
 * (utility bill, supplier cash payment, etc.). The MERCHANT
 * PORTAL surface is a review page — list, approve (mark
 * reviewed), reject (with reason), annotate. Expenses feed the
 * net-profit line of the Sales Report (§5.11.1).
 *
 * Provenance is split across two nullable FKs:
 *   - logged_by_pos_staff_id   — set when the expense came from
 *                                a POS device (Phase 8 sync feed).
 *   - logged_by_portal_user_id — set when a back-office portal
 *                                user logged it directly. The
 *                                blueprint scopes the portal as
 *                                review-only, but until the POS
 *                                app exists (Phase 9) the portal
 *                                needs a create path so the page
 *                                isn't permanently empty. Exactly
 *                                one of the two is set per row.
 *
 * status lifecycle (App\Enums\ExpenseStatus):
 *   recorded → reviewed   (ReviewExpenseAction)
 *   recorded → rejected   (RejectExpenseAction, review_note req.)
 * No hard delete — a rejected expense stays for the audit trail
 * and is simply excluded from the net-profit rollup.
 *
 * Money: amount is decimal(12,3) for OMR baisas precision.
 *
 * This migration OWNS the shared pos_expenses table; pos_merchant
 * mirrors it in its sqlite test schema only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_expenses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->foreignId('branch_id')
                ->constrained('pos_branches')
                ->cascadeOnDelete();

            // 32-char string. App enum {@see App\Enums\ExpenseCategory}:
            // utilities / supplies / maintenance / salaries / other.
            $table->string('category', 32);

            // OMR amount, decimal:3 baisas precision.
            $table->decimal('amount', 12, 3);

            $table->text('note')->nullable();

            // Relative path to the uploaded receipt image. The POS
            // device captures it; the portal renders a thumbnail.
            $table->string('receipt_photo_path')->nullable();

            // Provenance — exactly one of these is set.
            $table->foreignId('logged_by_pos_staff_id')
                ->nullable()
                ->constrained('pos_staff')
                ->nullOnDelete();
            $table->foreignId('logged_by_portal_user_id')
                ->nullable()
                ->constrained('pos_users')
                ->nullOnDelete();
            $table->timestamp('logged_at')->useCurrent();

            // 32-char string. App enum {@see App\Enums\ExpenseStatus}:
            // recorded / reviewed / rejected.
            $table->string('status', 32)->default('recorded');
            $table->foreignId('reviewed_by_portal_user_id')
                ->nullable()
                ->constrained('pos_users')
                ->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->timestamps();

            // "expenses needing review for company X" — the portal
            // review queue's default filter.
            $table->index(['company_id', 'status'], 'pos_expenses_company_status_idx');
            // "expenses in a window for a branch" — the §5.11.1
            // net-profit rollup + the review page's date/branch filter.
            $table->index(['company_id', 'branch_id', 'logged_at'], 'pos_expenses_company_branch_logged_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_expenses');
    }
};
