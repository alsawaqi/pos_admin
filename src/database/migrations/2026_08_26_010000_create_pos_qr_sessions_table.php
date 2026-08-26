<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QR rotation sessions displayed by payment-station devices.
 *
 * One row represents one QR token rotation. Tokens and UUIDs are unique,
 * while the two composite indexes support station lookups and expiry pruning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_qr_sessions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')
                ->constrained('pos_companies')
                ->cascadeOnDelete();
            $table->foreignId('branch_id')
                ->constrained('pos_branches')
                ->cascadeOnDelete();
            $table->foreignId('device_id')
                ->constrained('pos_devices')
                ->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('token_expires_at');
            $table->string('status', 16)->default('pending');
            $table->string('client_secret_hash', 64)->nullable();
            $table->timestamp('bound_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'status'], 'pos_qr_sessions_device_status_idx');
            $table->index(['status', 'expires_at'], 'pos_qr_sessions_status_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_qr_sessions');
    }
};
