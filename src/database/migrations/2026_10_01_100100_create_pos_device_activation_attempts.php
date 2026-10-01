<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P1 B1 — activation attempts the serial/app lock refused (enforce) or
 * let through with a recorded mismatch (report). Written by pos_api, shown on
 * the admin device page. The reported serial is stored masked + hashed only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_device_activation_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('activation_token_id')->nullable();
            $table->string('outcome', 16); // refused | reported
            $table->string('reason', 48); // activation_serial_missing | activation_device_mismatch | activation_app_mismatch
            $table->string('binding_mode', 8); // report | enforce
            $table->string('reported_serial_masked', 32)->nullable();
            $table->string('reported_serial_hash', 64)->nullable();
            $table->string('app', 32)->nullable();
            $table->string('manufacturer', 128)->nullable();
            $table->string('model', 128)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['device_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_device_activation_attempts');
    }
};
