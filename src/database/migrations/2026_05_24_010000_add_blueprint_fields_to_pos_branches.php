<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catches pos_branches up to the MITHQAL 2.0 blueprint §4.3.2 / §10.1.
 *
 * Adds the bilingual Arabic name, the per-branch geo-fence radius (server-
 * side enforced on every POS request), the weekly opening hours, and the
 * default order type that the Main POS lands on when an operator opens a
 * new ticket. Everything else on the table already matched the spec.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_branches', function (Blueprint $table): void {
            $table->string('name_ar')->nullable()->after('name');
            $table->unsignedSmallInteger('geofence_radius_m')->default(500)->after('longitude');
            $table->json('opening_hours_json')->nullable()->after('geofence_radius_m');
            $table->string('default_order_type', 16)->default('quick')->after('opening_hours_json');
        });
    }

    public function down(): void
    {
        Schema::table('pos_branches', function (Blueprint $table): void {
            $table->dropColumn([
                'name_ar',
                'geofence_radius_m',
                'opening_hours_json',
                'default_order_type',
            ]);
        });
    }
};
