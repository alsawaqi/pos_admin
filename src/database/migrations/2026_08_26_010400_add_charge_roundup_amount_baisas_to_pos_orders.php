<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable round-up intent captured with a QR charge claim.
 *
 * Nullable keeps historical and non-QR orders inert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->unsignedInteger('charge_roundup_amount_baisas')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropColumn('charge_roundup_amount_baisas');
        });
    }
};
