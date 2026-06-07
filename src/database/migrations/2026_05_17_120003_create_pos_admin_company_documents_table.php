<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_admin_company_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('pos_admin_companies')->cascadeOnDelete();
            $table->string('document_type', 64)->index();
            $table->string('disk', 64);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64)->index();

            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('pos_admin_users')->nullOnDelete();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('pos_admin_users')->nullOnDelete();
            $table->string('verification_status', 32)->default('pending')->index();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable()->index();
            $table->text('notes')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'document_type'], 'pos_admin_company_documents_company_type_index');
            $table->index(['company_id', 'verification_status'], 'pos_admin_company_documents_company_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_admin_company_documents');
    }
};
