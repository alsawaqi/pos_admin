<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P1 P1-2 / P1-8 — one token table for every set-password link.
 *
 * pos_password_reset_tokens (user_id keyed, SHA-256 hash, expiry,
 * single-use) already carries the merchant portal's forgot-password
 * links. The admin's "set-password link" for a new portal user or a new
 * admin, the admin-issued reset for a merchant user and the admin
 * portal's own forgot-password now use the same rows:
 *
 *   purpose            invite (72 h) | reset (60 min) | forgot (60 min)
 *   issued_by_user_id  the admin who issued an invite/reset (NULL for a
 *                      self-service forgot-password link)
 *
 * Existing rows are forgot-password links, hence the default. Additive
 * only; the down() drops just these two columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_password_reset_tokens', function (Blueprint $table): void {
            $table->string('purpose', 16)->default('forgot');
            $table->unsignedBigInteger('issued_by_user_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('pos_password_reset_tokens', function (Blueprint $table): void {
            $table->dropIndex(['issued_by_user_id']);
            $table->dropColumn(['purpose', 'issued_by_user_id']);
        });
    }
};
