<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Admin\AuditLogsController;
use App\Http\Controllers\Api\Admin\BanksController;
use App\Http\Controllers\Api\Admin\BankReconciliationController;
use App\Http\Controllers\Api\Admin\CitiesController;
use App\Http\Controllers\Api\Admin\CountriesController;
use App\Http\Controllers\Api\Admin\DistrictsController;
use App\Http\Controllers\Api\Admin\RegionsController;
use App\Http\Controllers\Api\Admin\BranchesController;
use App\Http\Controllers\Api\Admin\BusinessActivitiesController;
use App\Http\Controllers\Api\Admin\CommissionProfilesController;
use App\Http\Controllers\Api\Admin\DashboardSummaryController;
use App\Http\Controllers\Api\Admin\DeviceMakesController;
use App\Http\Controllers\Api\Admin\DeviceModelsController;
use App\Http\Controllers\Api\Admin\DevicesController;
use App\Http\Controllers\Api\Admin\MerchantActivitiesController;
use App\Http\Controllers\Api\Admin\MerchantDocumentVerificationController;
use App\Http\Controllers\Api\Admin\MerchantDocumentsController;
use App\Http\Controllers\Api\Admin\MerchantStatusController;
use App\Http\Controllers\Api\Admin\MerchantsController;
use App\Http\Controllers\Api\Admin\PlatformTeamController;
use App\Http\Controllers\Api\Admin\RolesController;
use App\Http\Controllers\Api\Admin\PortalUsersController;
use App\Http\Controllers\Api\Admin\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'pos.admin.session', 'pos.tenant'])
    ->prefix('admin/api/v1')
    ->name('admin.api.v1.')
    ->group(function (): void {
        // Commission profiles — read-only listing for the Register
        // Device dropdown. Authoritative writes live in the charity
        // application; POS only reads.
        Route::get('commission-profiles', [CommissionProfilesController::class, 'index'])
            ->name('commission-profiles.index');

        // Banks — read-only listing for the Register Device dropdown.
        // Same shape as commission-profiles: authoritative writes
        // live in the charity application; POS only reads.
        Route::get('banks', [BanksController::class, 'index'])
            ->name('banks.index');

        // Bank reconciliation: upload a bank settlement sheet, match it
        // against pos_payments by terminal_id + auth code, then commit the
        // matched tenders as reconciled. Parser dispatched by bank_id.
        Route::post('bank-reconciliation/preview', [BankReconciliationController::class, 'preview'])
            ->name('bank-reconciliation.preview');
        Route::post('bank-reconciliation/commit', [BankReconciliationController::class, 'commit'])
            ->name('bank-reconciliation.commit');

        // Geography reference data (shared charity tables): read is open to
        // any admin; mutations are gated by settings.manage in the controllers.
        Route::get('countries', [CountriesController::class, 'index'])->name('countries.index');
        Route::post('countries', [CountriesController::class, 'store'])->name('countries.store');
        Route::patch('countries/{country}', [CountriesController::class, 'update'])->name('countries.update');
        Route::delete('countries/{country}', [CountriesController::class, 'destroy'])->name('countries.destroy');

        Route::get('regions', [RegionsController::class, 'index'])->name('regions.index');
        Route::post('regions', [RegionsController::class, 'store'])->name('regions.store');
        Route::patch('regions/{region}', [RegionsController::class, 'update'])->name('regions.update');
        Route::delete('regions/{region}', [RegionsController::class, 'destroy'])->name('regions.destroy');

        Route::get('districts', [DistrictsController::class, 'index'])->name('districts.index');
        Route::post('districts', [DistrictsController::class, 'store'])->name('districts.store');
        Route::patch('districts/{district}', [DistrictsController::class, 'update'])->name('districts.update');
        Route::delete('districts/{district}', [DistrictsController::class, 'destroy'])->name('districts.destroy');

        Route::get('cities', [CitiesController::class, 'index'])->name('cities.index');
        Route::post('cities', [CitiesController::class, 'store'])->name('cities.store');
        Route::patch('cities/{city}', [CitiesController::class, 'update'])->name('cities.update');
        Route::delete('cities/{city}', [CitiesController::class, 'destroy'])->name('cities.destroy');

        // Business Activities catalogue — index is read-only for any
        // authenticated admin (merchant wizard needs it); store /
        // update / destroy require the BusinessActivitiesManage
        // permission, enforced by BusinessActivityPolicy.
        Route::get('business-activities', [BusinessActivitiesController::class, 'index'])
            ->name('business-activities.index');
        Route::post('business-activities', [BusinessActivitiesController::class, 'store'])
            ->name('business-activities.store');
        Route::patch('business-activities/{activity}', [BusinessActivitiesController::class, 'update'])
            ->name('business-activities.update');
        Route::delete('business-activities/{activity}', [BusinessActivitiesController::class, 'destroy'])
            ->name('business-activities.destroy');

        Route::get('merchants', [MerchantsController::class, 'index'])->name('merchants.index');
        Route::post('merchants', [MerchantsController::class, 'store'])->name('merchants.store');

        Route::scopeBindings()->group(function (): void {
            Route::get('merchants/{merchant:uuid}', [MerchantsController::class, 'show'])->name('merchants.show');
            Route::patch('merchants/{merchant:uuid}', [MerchantsController::class, 'update'])->name('merchants.update');
            // Soft-delete a merchant. Refuses with 409 when there
            // are still active branches OR devices — admin must
            // clean those up first (see DeleteMerchantAction).
            Route::delete('merchants/{merchant:uuid}', [MerchantsController::class, 'destroy'])->name('merchants.destroy');

            Route::post('merchants/{merchant:uuid}/status', [MerchantStatusController::class, 'store'])
                ->name('merchants.status.store');

            Route::put('merchants/{merchant:uuid}/activities', [MerchantActivitiesController::class, 'update'])
                ->name('merchants.activities.update');

            Route::get('merchants/{merchant:uuid}/documents', [MerchantDocumentsController::class, 'index'])
                ->name('merchants.documents.index');
            Route::post('merchants/{merchant:uuid}/documents', [MerchantDocumentsController::class, 'store'])
                ->name('merchants.documents.store');
            Route::get('merchants/{merchant:uuid}/documents/{document:uuid}', [MerchantDocumentsController::class, 'show'])
                ->name('merchants.documents.show');
            Route::get('merchants/{merchant:uuid}/documents/{document:uuid}/download', [MerchantDocumentsController::class, 'download'])
                ->name('merchants.documents.download');
            Route::delete('merchants/{merchant:uuid}/documents/{document:uuid}', [MerchantDocumentsController::class, 'destroy'])
                ->name('merchants.documents.destroy');

            Route::post('merchants/{merchant:uuid}/documents/{document:uuid}/verify',
                [MerchantDocumentVerificationController::class, 'verify'])
                ->name('merchants.documents.verify');
            Route::post('merchants/{merchant:uuid}/documents/{document:uuid}/reject',
                [MerchantDocumentVerificationController::class, 'reject'])
                ->name('merchants.documents.reject');

            // Merchant Portal Users (blueprint §4.5). Nested under
            // the merchant route so the policy + tenant guard always
            // have the parent company in scope. The portalUser model
            // binding resolves implicitly by id (no uuid yet).
            Route::get('merchants/{merchant:uuid}/portal-users',
                [PortalUsersController::class, 'index'])->name('merchants.portal-users.index');
            Route::post('merchants/{merchant:uuid}/portal-users',
                [PortalUsersController::class, 'store'])->name('merchants.portal-users.store');
            Route::patch('merchants/{merchant:uuid}/portal-users/{portalUser}',
                [PortalUsersController::class, 'update'])->name('merchants.portal-users.update');
            // Was: /resend-invite (sent a welcome email).
            // Now : /reset-password (generates a new pw, returns
            // plaintext ONCE) — matches the create-with-password
            // flow that replaced the invite-by-email model. The
            // old route URL is deliberately gone; the SPA migrated
            // in the same change.
            Route::post('merchants/{merchant:uuid}/portal-users/{portalUser}/reset-password',
                [PortalUsersController::class, 'resetPassword'])->name('merchants.portal-users.reset-password');

            Route::get('branches/{branch:uuid}', [BranchesController::class, 'show'])->name('branches.show');
            Route::patch('branches/{branch:uuid}', [BranchesController::class, 'update'])->name('branches.update');
            // Soft-delete a branch. Refuses with 409 when active
            // devices are still assigned — see DeleteBranchAction.
            Route::delete('branches/{branch:uuid}', [BranchesController::class, 'destroy'])->name('branches.destroy');

            // Devices nested actions (blueprint §4.4). The /assign and
            // /unassign endpoints are POST not PATCH because they
            // trigger workflow side-effects (history row open/close +
            // audit log) rather than a vanilla field update.
            Route::get('devices/{device:uuid}', [DevicesController::class, 'show'])->name('devices.show');
            Route::post('devices/{device:uuid}/assign', [DevicesController::class, 'assign'])->name('devices.assign');
            Route::post('devices/{device:uuid}/unassign', [DevicesController::class, 'unassign'])->name('devices.unassign');
            // Decommission: closes any open assignment row, sets
            // status=Blocked, soft-deletes the device. Gated by
            // DevicesDecommission permission.
            Route::post('devices/{device:uuid}/decommission', [DevicesController::class, 'decommission'])->name('devices.decommission');
            // Lane A — mint a one-shot activation code. The
            // Android cashier app exchanges it on pos_merchant for
            // a long-lived Sanctum PAT. Gated by DevicesActivate.
            Route::post('devices/{device:uuid}/activation-token', [DevicesController::class, 'issueActivationToken'])->name('devices.activation-token');
        });

        Route::get('branches', [BranchesController::class, 'index'])->name('branches.index');
        Route::post('branches', [BranchesController::class, 'store'])->name('branches.store');

        // Top-level devices endpoints: list + register.
        Route::get('devices', [DevicesController::class, 'index'])->name('devices.index');
        Route::post('devices', [DevicesController::class, 'store'])->name('devices.store');

        // Device Makes catalogue (Settings → Device catalogue).
        // viewAny is open to any authenticated admin so the
        // Register Device form's Make dropdown works for everyone;
        // mutations require DeviceModelsManage, enforced by
        // DeviceMakePolicy.
        Route::get('device-makes', [DeviceMakesController::class, 'index'])
            ->name('device-makes.index');
        Route::post('device-makes', [DeviceMakesController::class, 'store'])
            ->name('device-makes.store');
        Route::patch('device-makes/{make}', [DeviceMakesController::class, 'update'])
            ->name('device-makes.update');
        Route::delete('device-makes/{make}', [DeviceMakesController::class, 'destroy'])
            ->name('device-makes.destroy');

        // Device Models nested under makes — scopeBindings keeps a
        // model from being addressable under the wrong make's URL
        // (returns 404 on cross-make mismatches).
        Route::scopeBindings()->group(function (): void {
            Route::get('device-makes/{make}/models',
                [DeviceModelsController::class, 'index'])->name('device-makes.models.index');
            Route::post('device-makes/{make}/models',
                [DeviceModelsController::class, 'store'])->name('device-makes.models.store');
            Route::patch('device-makes/{make}/models/{model}',
                [DeviceModelsController::class, 'update'])->name('device-makes.models.update');
            Route::delete('device-makes/{make}/models/{model}',
                [DeviceModelsController::class, 'destroy'])->name('device-makes.models.destroy');
        });

        // Platform Team — admin user CRUD. Gated by
        // PlatformUsers* permissions inside the controller (no
        // Policy class because PortalUserPolicy already owns
        // User::class — see PlatformTeamController docstring).
        Route::get('platform-team', [PlatformTeamController::class, 'index'])
            ->name('platform-team.index');
        Route::post('platform-team', [PlatformTeamController::class, 'store'])
            ->name('platform-team.store');
        Route::patch('platform-team/{user}', [PlatformTeamController::class, 'update'])
            ->name('platform-team.update');
        Route::post('platform-team/{user}/suspend', [PlatformTeamController::class, 'suspend'])
            ->name('platform-team.suspend');
        Route::post('platform-team/{user}/reactivate', [PlatformTeamController::class, 'reactivate'])
            ->name('platform-team.reactivate');
        // Phase 4.8b — replace a platform user's role list with
        // a new set. Gated on platform_users.update_roles (NOT
        // on platform_users.update — role mutation is a meta-
        // control separate from "edit name / phone").
        Route::patch('platform-team/{user}/roles', [PlatformTeamController::class, 'assignRoles'])
            ->name('platform-team.assign-roles');

        // -------- Phase 4.8b — Roles & Permissions builder --------
        // Manages the roles that live under team_id=0 (platform
        // sentinel). Gated by roles.{view,manage} inside the
        // controller — same "no Policy class, direct can()"
        // pattern as PlatformTeamController.
        Route::get('roles/catalog', [RolesController::class, 'catalog'])
            ->name('roles.catalog');
        Route::get('roles', [RolesController::class, 'index'])
            ->name('roles.index');
        Route::post('roles', [RolesController::class, 'store'])
            ->name('roles.store');
        Route::patch('roles/{role}', [RolesController::class, 'update'])
            ->name('roles.update');
        Route::delete('roles/{role}', [RolesController::class, 'destroy'])
            ->name('roles.destroy');

        // Dashboard summary (blueprint §4.8 — Slim Sprint 2 scope).
        // Single-shot KPI payload for the admin landing page.
        // No specific permission gate — every authed admin can see
        // the landing page; deep-links into Merchants/Devices/Audit
        // re-check at the destination.
        Route::get('dashboard/summary', DashboardSummaryController::class)
            ->name('dashboard.summary');

        // Platform Settings — generic key/value catalogue editable
        // from the Settings page. Read open to any authenticated
        // admin; write gated by SettingsManage inside the controller.
        Route::get('settings', [SettingsController::class, 'index'])
            ->name('settings.index');
        Route::patch('settings', [SettingsController::class, 'update'])
            ->name('settings.update');

        // Audit Log Viewer (blueprint §4.7 — Sprint 1.5, final Phase
        // 2 piece). Both routes share filter parsing inside the
        // controller so the CSV reflects exactly the on-screen query.
        // Gated by AuditLogPolicy / AuditLogsView permission.
        // The literal `.csv` suffix means the export URL can be
        // window.open()-ed in the browser without a Content-Disposition
        // round-trip — the file just downloads with its true extension.
        Route::get('audit-logs', [AuditLogsController::class, 'index'])
            ->name('audit-logs.index');
        Route::get('audit-logs/export.csv', [AuditLogsController::class, 'export'])
            ->name('audit-logs.export');
    });
