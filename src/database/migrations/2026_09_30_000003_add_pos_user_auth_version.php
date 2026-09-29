<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_users', fn (Blueprint $table) => $table->unsignedBigInteger('auth_version')->default(0));
    }

    public function down(): void
    {
        Schema::table('pos_users', fn (Blueprint $table) => $table->dropColumn('auth_version'));
    }
};
