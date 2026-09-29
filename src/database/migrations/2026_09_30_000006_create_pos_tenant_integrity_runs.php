<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_tenant_integrity_runs', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 20)->default('running');
            $table->unsignedBigInteger('violation_count')->default(0);
            $table->json('checks')->nullable();
            $table->index(['started_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_tenant_integrity_runs');
    }
};
