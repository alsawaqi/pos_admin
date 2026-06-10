<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedicated reset-token table for the POS portals' self-service
 * forgot-password flow (Phase D7; blueprint §4.1.1 / §5.1.1 /
 * §11.1 "POST /auth/portal/forgot-password").
 *
 * Why NOT Laravel's Password broker + its default table:
 *   - The shared charity_db already contains a `password_reset_tokens`
 *     table that belongs to the CHARITY app (email-keyed). Pointing
 *     the broker at it would read/write another application's
 *     tokens.
 *   - The broker has no concept of user_type scoping — pos_users
 *     hosts both platform_admin and merchant rows, and a reset
 *     minted from the merchant portal must never be able to take
 *     over an admin account (and vice versa). Keying by user_id +
 *     scoping the lookup per portal closes that.
 *
 * Token semantics (mirrors the proven setup_token convention from
 * 2026_05_24_040000_add_portal_user_fields_to_pos_users):
 *   - token_hash — SHA-256 of the raw 64-char token; the raw value
 *     only ever exists in the email body, so a DB dump cannot be
 *     replayed into an account takeover.
 *   - expires_at — short wall-clock expiry (60 min, set by the app).
 *   - used_at    — single-use stamp; a consumed token is dead even
 *                  inside its expiry window.
 *
 * pos_admin owns all shared-table migrations; pos_merchant mirrors
 * this shape in its sqlite test schema only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_password_reset_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('pos_users')->cascadeOnDelete();
            // 64 hex chars = SHA-256 output. Unique so the reset
            // endpoint can look the row up by hash directly.
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_password_reset_tokens');
    }
};
