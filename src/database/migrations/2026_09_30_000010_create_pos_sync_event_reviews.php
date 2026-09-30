<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sync_event_reviews', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('sync_event_id')->unique();
            $t->unsignedBigInteger('actor_user_id');
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id');
            $t->string('fingerprint', 64);
            $t->text('reason');
            $t->jsonb('device_snapshot');
            $t->string('status')->default('attributed');
            $t->timestamps();
        });
    }

    public function down(): void
    { /* Preserve attribution and audit evidence. */
    }
};
