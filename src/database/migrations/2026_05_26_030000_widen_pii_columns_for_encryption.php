<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen PII columns to TEXT so they can hold encrypted ciphertext.
 *
 * Why this is necessary:
 *   Laravel's `encrypted` cast wraps every value with
 *   {@see \Illuminate\Encryption\Encrypter::encryptString()} — AES-256-CBC
 *   + HMAC + base64. The ciphertext grows roughly 3-4× the plaintext
 *   length plus a fixed overhead (~80 chars). A 50-char email becomes
 *   ~250 chars after encryption, which is right at the varchar(255)
 *   ceiling. To stay safe (and to not have to redo this migration the
 *   first time someone enters a long email), we move to TEXT.
 *
 * Columns covered (Sprint 3 scope — only existing-today PII):
 *   - pos_company_owners.civil_id
 *   - pos_company_owners.phone
 *   - pos_company_owners.email
 *   - pos_users.phone
 *
 * Deferred until the relevant tables exist (Phase 4+):
 *   - customers.phone, customers.email (no customer table yet)
 *   - customer_vehicles.plate_number (no vehicles table yet)
 *   - pos_staff.pin_hash, pos_staff.phone (no pos_staff table yet)
 *   - device bank-credential JSON (no payment data yet)
 *
 * Forward + reverse both safe to run repeatedly — they just change
 * the column type, never lose data.
 *
 * IMPORTANT: this migration must land BEFORE the model gains the
 * `encrypted` cast in App\Models\CompanyOwner + App\Models\User.
 * Otherwise the first save of a long value would silently truncate
 * after encryption.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_company_owners', function (Blueprint $table): void {
            $table->text('civil_id')->nullable()->change();
            $table->text('phone')->nullable()->change();
            $table->text('email')->nullable()->change();
        });

        Schema::table('pos_users', function (Blueprint $table): void {
            $table->text('phone')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reverse to the original varchar(255) shape. Note: if there's
        // already encrypted (longer) data in these columns, going back
        // to varchar will fail silently on Postgres (truncation error
        // raised by the driver). The recovery is to first decrypt the
        // values via an Artisan one-shot — see the
        // docs/runbooks/app-key-rotation.md runbook which walks
        // through the same decrypt/re-encrypt loop.
        Schema::table('pos_company_owners', function (Blueprint $table): void {
            $table->string('civil_id')->nullable()->change();
            $table->string('phone')->nullable()->change();
            $table->string('email')->nullable()->change();
        });

        Schema::table('pos_users', function (Blueprint $table): void {
            $table->string('phone')->nullable()->change();
        });
    }
};
