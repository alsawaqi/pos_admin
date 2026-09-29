<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_terminal_reservations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('bank_id');
            $table->string('terminal_id', 64);
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('device_id')->nullable();
            $table->timestamps();
            $table->unique(['bank_id', 'terminal_id']);
        });
        foreach (DB::table('pos_devices')->whereNotNull('company_id')->whereNotNull('bank_id')->whereNotNull('terminal_id')->cursor() as $device) {
            DB::table('pos_terminal_reservations')->insert([
                'bank_id' => $device->bank_id,
                'terminal_id' => strtoupper(preg_replace('/\s+/', '', trim($device->terminal_id))),
                'company_id' => $device->company_id, 'device_id' => $device->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_terminal_reservations');
    }
};
