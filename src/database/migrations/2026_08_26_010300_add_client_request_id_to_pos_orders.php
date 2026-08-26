<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customer-scoped idempotency for QR order submission.
     *
     * The key is nullable so historical and non-QR orders remain valid. Its
     * uniqueness is scoped to a QR session, allowing the same opaque request id
     * to be reused independently by different customer sessions.
     */
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->string('client_request_id', 64)->nullable();
            $table->unique(
                ['qr_session_id', 'client_request_id'],
                'pos_orders_qr_session_request_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropUnique('pos_orders_qr_session_request_unique');
            $table->dropColumn('client_request_id');
        });
    }
};
