<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['pos_orders', 'pos_roundup_donations'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropUnique($name.'_client_event_id_unique');
                $table->unique(['device_id', 'client_event_id'], $name.'_device_event_unique');
            });
        }
        Schema::table('pos_qr_sessions', fn (Blueprint $table) => $table->string('table_qr_token_hash', 64)->nullable());
    }

    public function down(): void
    {
        // Fails safely if IDs now repeat between devices; do not delete data to roll back.
        foreach (['pos_orders', 'pos_roundup_donations'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->unique('client_event_id', $name.'_client_event_id_unique');
                $table->dropUnique($name.'_device_event_unique');
            });
        }
        Schema::table('pos_qr_sessions', fn (Blueprint $table) => $table->dropColumn('table_qr_token_hash'));
    }
};
