<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_admin_companies', function (Blueprint $table): void {
            $table->string('name_ar')->nullable()->after('name');
            $table->string('legal_name_ar')->nullable()->after('legal_name');

            $table->renameColumn('commercial_registration_number', 'cr_number');
        });

        Schema::table('pos_admin_companies', function (Blueprint $table): void {
            $table->date('cr_issue_date')->nullable()->after('cr_number');
            $table->date('cr_expiry_date')->nullable()->after('cr_issue_date');
            $table->date('establishment_date')->nullable()->after('cr_expiry_date');

            $table->string('vat_number')->nullable()->after('tax_number');
            $table->date('vat_registered_at')->nullable()->after('vat_number');

            $table->string('chamber_of_commerce_number')->nullable()->after('vat_registered_at');
            $table->string('municipality_license_number')->nullable()->after('chamber_of_commerce_number');

            $table->string('owner_full_name_en')->nullable()->after('contact_email');
            $table->string('owner_full_name_ar')->nullable()->after('owner_full_name_en');
            $table->string('owner_civil_id')->nullable()->after('owner_full_name_ar');
            $table->string('owner_nationality', 2)->nullable()->after('owner_civil_id');
            $table->string('owner_phone')->nullable()->after('owner_nationality');
            $table->string('owner_email')->nullable()->after('owner_phone');

            $table->string('default_currency', 3)->default('OMR')->after('owner_email');
            $table->string('default_locale', 10)->default('en')->after('default_currency');

            $table->foreignId('onboarded_by_user_id')->nullable()->after('default_locale')
                ->constrained('pos_admin_users')->nullOnDelete();

            $table->timestamp('activated_at')->nullable()->after('status');
            $table->timestamp('suspended_at')->nullable()->after('activated_at');
            $table->text('suspension_reason')->nullable()->after('suspended_at');

            // cr_number keeps the auto-named index from the original migration after rename.
            $table->index('vat_number', 'pos_admin_companies_vat_number_index');
            $table->index('cr_expiry_date', 'pos_admin_companies_cr_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::table('pos_admin_companies', function (Blueprint $table): void {
            $table->dropIndex('pos_admin_companies_vat_number_index');
            $table->dropIndex('pos_admin_companies_cr_expiry_index');

            $table->dropConstrainedForeignId('onboarded_by_user_id');

            $table->dropColumn([
                'name_ar',
                'legal_name_ar',
                'cr_issue_date',
                'cr_expiry_date',
                'establishment_date',
                'vat_number',
                'vat_registered_at',
                'chamber_of_commerce_number',
                'municipality_license_number',
                'owner_full_name_en',
                'owner_full_name_ar',
                'owner_civil_id',
                'owner_nationality',
                'owner_phone',
                'owner_email',
                'default_currency',
                'default_locale',
                'activated_at',
                'suspended_at',
                'suspension_reason',
            ]);

            $table->renameColumn('cr_number', 'commercial_registration_number');
        });
    }
};
