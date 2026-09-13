<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_payment_reversals', function (Blueprint $table): void {
            $table->boolean('refund_needs_transaction_id')->default(false);
            $table->boolean('void_needs_session_id')->default(false);
        });
        Schema::create('pos_payment_reversal_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reversal_id')->constrained('pos_payment_reversals')->restrictOnDelete();
            $table->foreignId('device_id')->constrained('pos_devices')->restrictOnDelete();
            $table->string('client_request_id', 64);
            $table->string('request_fingerprint', 64);
            $table->jsonb('response_json');
            $table->timestamp('created_at');
            $table->unique(['device_id', 'client_request_id'], 'pos_reversal_results_device_request_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_payment_reversal_results');
        Schema::table('pos_payment_reversals', fn (Blueprint $table) => $table->dropColumn([
            'refund_needs_transaction_id', 'void_needs_session_id',
        ]));
    }
};
