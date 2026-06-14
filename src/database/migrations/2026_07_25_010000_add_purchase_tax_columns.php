<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PT (purchase/input tax) — optional tax-paid tracking on the buy side.
 *
 * The merchant already configures SALES taxes (pos_taxes, added on top of an
 * order). This is the mirror for PURCHASES: when recording a cost (a Goods
 * Received Note line, a stock receive, a named charge, or a manual expense) the
 * merchant may also record the tax they PAID, so the reports can total "how
 * much tax did I pay on purchases".
 *
 * The model is exclusive + consistent everywhere: the cost field stays the NET
 * (before-tax) amount; tax_amount is the tax ON TOP (a rate from the company's
 * tax list, or a typed amount; tax_rate is the % when a rate was used, NULL for
 * a manual amount). The booked pos_expenses row's `amount` stays the GROSS
 * (net + tax = what actually left the till — the cash-model P&L is unchanged),
 * and its `tax_amount` is the tax portion of that amount. All default 0 / NULL,
 * so an untaxed purchase behaves exactly as before.
 *
 * Whether the tracked tax is merely informational or RECOVERABLE (subtracted
 * from operating expenses, raising net profit) is a per-company report choice
 * stored as the `purchase_tax_recoverable` pos_company_settings row — no DDL.
 *
 * Schema owner: pos_admin. Mirrored into pos_merchant's test schema. No pos_api
 * / pos_machine change — purchase tax is a portal data-entry + reporting concept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_expenses', function (Blueprint $table): void {
            // The tax portion OF `amount` (which stays the gross paid).
            $table->decimal('tax_amount', 12, 3)->default(0)->after('amount');
            // The % rate applied (e.g. 5.00), NULL when a manual amount was typed.
            $table->decimal('tax_rate', 5, 2)->nullable()->after('tax_amount');
        });

        Schema::table('pos_purchase_receipts', function (Blueprint $table): void {
            // Σ of every line + charge tax; grand_total = items + charges + tax.
            $table->decimal('tax_total', 12, 3)->default(0)->after('charges_total');
        });

        Schema::table('pos_purchase_receipt_lines', function (Blueprint $table): void {
            // line_cost stays the NET (before-tax) item cost; this is the tax on top.
            $table->decimal('tax_amount', 12, 3)->default(0)->after('line_cost');
            $table->decimal('tax_rate', 5, 2)->nullable()->after('tax_amount');
        });

        Schema::table('pos_purchase_receipt_charges', function (Blueprint $table): void {
            // amount stays the NET charge; this is the tax on top.
            $table->decimal('tax_amount', 12, 3)->default(0)->after('amount');
            $table->decimal('tax_rate', 5, 2)->nullable()->after('tax_amount');
        });
    }

    public function down(): void
    {
        Schema::table('pos_purchase_receipt_charges', function (Blueprint $table): void {
            $table->dropColumn(['tax_amount', 'tax_rate']);
        });
        Schema::table('pos_purchase_receipt_lines', function (Blueprint $table): void {
            $table->dropColumn(['tax_amount', 'tax_rate']);
        });
        Schema::table('pos_purchase_receipts', function (Blueprint $table): void {
            $table->dropColumn('tax_total');
        });
        Schema::table('pos_expenses', function (Blueprint $table): void {
            $table->dropColumn(['tax_amount', 'tax_rate']);
        });
    }
};
