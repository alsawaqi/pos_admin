<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opt-in TOTP two-factor authentication for BOTH portals (Phase D8;
 * blueprint §4.1.1 "Optional two-factor authentication via TOTP for
 * all admin accounts" / §5.1.1 "Two-factor recommended for Super
 * Admin").
 *
 * pos_users hosts both populations (platform_admin + merchant), and
 * both portals' login flows read these columns, so they live on the
 * shared table that pos_admin's migrations own.
 *
 * Column semantics:
 *   - two_factor_secret         — the RFC 6238 base32 secret,
 *     encrypted at rest via the model's 'encrypted' cast (admin +
 *     merchant share APP_KEY so the ciphertext interoperates). TEXT
 *     because Laravel ciphertext is ~3× the plaintext.
 *   - two_factor_recovery_codes — encrypted JSON array of SHA-256
 *     hashes of the one-time recovery codes; the plaintext codes are
 *     shown exactly once at enable time and never stored.
 *   - two_factor_confirmed_at   — NULL until the user proves
 *     possession of the authenticator by confirming a valid code.
 *     A secret WITHOUT a confirmed_at is a half-finished enrolment
 *     and never gates login.
 *
 * pos_merchant mirrors this shape in its sqlite test schema only;
 * pos_api never touches pos_users (verified — zero references).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_users', function (Blueprint $table): void {
            $table->text('two_factor_secret')->nullable()->after('must_change_password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('pos_users', function (Blueprint $table): void {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
            ]);
        });
    }
};
