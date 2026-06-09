<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-branch custom receipt template (merchant-authored).
 *
 * The merchant fills in their own receipt header/footer per branch —
 * business name (EN/AR), Commercial Registration (CR) number, VAT
 * number, address, phone, plus free header/footer lines and a couple
 * of toggles. The template flows to the device via /device/config and
 * is what the POS machine prints. NULL = use the built-in default
 * receipt (so existing branches keep printing unchanged).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_branches', function (Blueprint $table): void {
            $table->json('receipt_template')->nullable()->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('pos_branches', function (Blueprint $table): void {
            $table->dropColumn('receipt_template');
        });
    }
};
