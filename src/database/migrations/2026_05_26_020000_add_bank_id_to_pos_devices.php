<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `bank_id` to pos_devices — the acquiring bank that owns this
 * device's terminal_id.
 *
 * Why a bank binding on the device row?
 *   A merchant can use multiple banks across their estate (Bank
 *   Muscat handles one branch, NBO handles another). The terminal_id
 *   only makes sense scoped to a specific bank, so the device needs
 *   to carry the bank reference to disambiguate reconciliation.
 *   Without bank_id, the reconciler would have to guess which bank's
 *   API to call to verify a transaction by terminal_id.
 *
 * Same shape as the {@see commission_profile_id} FK added in
 * 2026_05_25_020000 — both reference charity-owned tables in the
 * shared Postgres instance (§3.2). nullable in the schema so any
 * pre-existing test/seed rows survive the migration; the
 * FormRequest layer enforces REQUIRED on new registrations.
 *
 * restrictOnDelete because accidentally removing a bank that's
 * bound to live devices would orphan terminal_ids and break the
 * bank reconciler. The charity-side admin would have to first
 * unbind it everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_devices', function (Blueprint $table): void {
            // After commission_profile_id keeps the bank metadata
            // columns clustered in column-listing order.
            $table->foreignId('bank_id')
                ->nullable()
                ->after('commission_profile_id')
                ->constrained('banks')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_devices', function (Blueprint $table): void {
            // dropConstrainedForeignId removes the column AND the
            // FK + index in one call.
            $table->dropConstrainedForeignId('bank_id');
        });
    }
};
