<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 (advertiser billing) — the INVOICE: a period's delivered
 * impressions priced at the advertiser's rate card, frozen.
 *
 * Everything needed to reproduce the bill is SNAPSHOT at issue time
 * (pricing_model, rate, the metered quantities) so later rate-card edits
 * or impression backfills never rewrite an issued financial document.
 * Lifecycle mirrors pos_commission_invoices: issued → paid, or void
 * (with a reason note); a voided period can be re-issued. No payment
 * gateway — the owner marks payments received (invoice-first model).
 *
 * Duplicate protection is action-level (CreateAdInvoiceAction refuses an
 * overlapping non-void invoice under lockForUpdate) — a cross-driver
 * partial unique on (advertiser, period, status<>void) is not portable.
 *
 * The advertiser-facing copy is read by marketing-api over the shared
 * table (read-only), scoped to the logged-in advertiser.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_ad_invoices')) {
            return;
        }

        Schema::create('pos_ad_invoices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('advertiser_id')->index();
            $table->date('period_from');
            $table->date('period_to');
            $table->string('status', 12)->default('issued')->index();
            // Pricing snapshot.
            $table->string('pricing_model', 32);
            $table->decimal('rate', 12, 3);
            // Delivery snapshot (metered from pos_marketing_impressions).
            $table->unsignedInteger('impressions_count')->default(0);
            $table->unsignedBigInteger('play_seconds')->default(0);
            $table->unsignedInteger('screen_days')->default(0);
            $table->decimal('amount', 12, 3);
            $table->text('note')->nullable();
            $table->foreignId('issued_by_user_id')->nullable()
                ->constrained('pos_users')->nullOnDelete();
            $table->foreignId('paid_by_user_id')->nullable()
                ->constrained('pos_users')->nullOnDelete();
            $table->foreignId('voided_by_user_id')->nullable()
                ->constrained('pos_users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
            $table->index(['advertiser_id', 'period_from', 'period_to'], 'pos_ad_invoices_advertiser_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_ad_invoices');
    }
};
