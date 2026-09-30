<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_devices', fn (Blueprint $table) => $table->timestamp('token_issued_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('pos_devices', fn (Blueprint $table) => $table->dropColumn('token_issued_at'));
    }
};
