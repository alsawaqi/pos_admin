<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff rounds share the append-only QR round ledger without a QR credential.
 * Keep the credential FK and ON DELETE CASCADE, and every existing index.
 * This table has no partial index to protect during SQLite's rebuild.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_qr_order_rounds', function (Blueprint $table): void {
            $table->unsignedBigInteger('qr_session_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Best effort: credential-free staff rounds cannot satisfy NOT NULL.
        DB::table('pos_qr_order_rounds')->whereNull('qr_session_id')->delete();

        Schema::table('pos_qr_order_rounds', function (Blueprint $table): void {
            $table->unsignedBigInteger('qr_session_id')->nullable(false)->change();
        });
    }
};
