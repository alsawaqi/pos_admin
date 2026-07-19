<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `pos_company_settings` table is OWNED by pos_merchant — it lives in the
 * shared charity_db and the merchant portal created it (position policies,
 * order numbering, …). pos_admin now WRITES one key of its own: the marketing
 * `audience_measurement` consent (task #46), toggled per merchant by the
 * platform admin and served to devices via pos_api /device/config meta.
 *
 * In production this migration is a NO-OP: the table already exists, so the
 * guard short-circuits. It exists purely so pos_admin's isolated test
 * database — which runs only pos_admin migrations — has the table available.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_company_settings')) {
            return;
        }

        Schema::create('pos_company_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('key', 64);
            $table->json('value')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'key'], 'pos_company_settings_company_key_unique');
        });
    }

    public function down(): void
    {
        // Never drop a table another app owns.
    }
};
