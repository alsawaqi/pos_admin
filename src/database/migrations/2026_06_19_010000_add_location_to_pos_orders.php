<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record WHERE an order was taken -- the device's GPS at order-create
 * time. The device already sends `gps` on the order.create sync event
 * (pos_api uses it for the section 9.4 geofence check); this persists
 * it so reports + support can see each order's origin point, not just
 * the branch's fixed location.
 *
 * decimal(10,7) nullable -- same precision as pos_branches / pos_payments
 * lat/lng (~1 cm). Nullable because GPS is best-effort (indoor fix loss,
 * customer-tablet flows that don't stamp it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->decimal('latitude', 10, 7)->nullable()->after('device_id');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
