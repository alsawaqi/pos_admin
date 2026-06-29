<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a slider plays. A row scopes the slider to a branch and/or a specific
 * device. A slider with NO target rows plays everywhere (all branches). Both
 * columns reference pos_admin-owned tables but are kept FK-free for flexibility
 * (validated in the request); device_id lets the admin pin a single screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_marketing_slider_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('slider_id')->constrained('pos_marketing_sliders')->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->unsignedBigInteger('device_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_marketing_slider_targets');
    }
};
