<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow general / company-wide expenses. pos_expenses.branch_id was
 * NOT NULL (every expense tied to a branch); make it nullable so
 * office / HQ / non-branch-staff costs can be logged with no branch.
 * A set branch_id still references a real branch; null = "general".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_expenses', function (Blueprint $table): void {
            $table->unsignedBigInteger('branch_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('pos_expenses', function (Blueprint $table): void {
            $table->unsignedBigInteger('branch_id')->nullable(false)->change();
        });
    }
};
