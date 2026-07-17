<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Admin\AuditLogsController;
use App\Http\Controllers\Api\Admin\OrdersController;
use App\Http\Controllers\Api\Admin\PayoutsController;
use App\Http\Controllers\Api\Admin\RoundUpReportController;
use App\Http\Controllers\Api\Admin\SalesReportController;
use App\Http\Controllers\Api\Admin\SettlementReportController;
use App\Http\Controllers\Api\Admin\BanksController;
use App\Http\Controllers\Api\Admin\BankReconciliationController;
use App\Http\Controllers\Api\Admin\PendingReconciliationController;
use App\Http\Controllers\Api\Admin\CommissionSettlementController;
use App\Http\Controllers\Api\Admin\CommissionInvoicesController;
use App\Http\Controllers\Api\Admin\CitiesController;
use App\Http\Controllers\Api\Admin\CountriesController;
use App\Http\Controllers\Api\Admin\DistrictsController;
use App\Http\Controllers\Api\Admin\RegionsController;
use App\Http\Controllers\Api\Admin\BranchesController;
use App\Http\Controllers\Api\Admin\BusinessActivitiesController;
use App\Http\Controllers\Api\Admin\CommissionProfilesController;
use App\Http\Controllers\Api\Admin\DashboardSummaryController;
use App\Http\Controllers\Api\Admin\OrganizationsController;
use App\Http\Controllers\Api\Admin\DeviceMakesController;
use App\Http\Controllers\Api\Admin\DeviceModelsController;
use App\Http\Controllers\Api\Admin\DeviceScalefusionController;
use App\Http\Controllers\Api\Admin\DevicesController;
use App\Http\Controllers\Api\Admin\MerchantActivitiesController;
use App\Http\Controllers\Api\Admin\MerchantCommissionProfileController;
use App\Http\Controllers\Api\Admin\MerchantDocumentVerificationController;
use App\Http\Controllers\Api\Admin\MerchantDocumentsController;
use App\Http\Controllers\Api\Admin\MerchantStatusController;
use App\Http\Controllers\Api\Admin\MarketingAdvertisersController;
use App\Http\Controllers\Api\Admin\MarketingContentController;
use App\Http\Controllers\Api\Admin\MarketingContentUploadController;
use App\Http\Controllers\Api\Admin\MarketingSlidersController;
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

        // Organizations — read-only listing for the Register Device dropdown
        // (the beneficiary org a device's round-up donations go to). Owned by
        // the charity application; POS only reads.
        Route::get('organizations', [OrganizationsController::class, 'index'])
            ->name('organizations.index');

        // Bank reconciliation: upload a bank settlement sheet, match it
        // against pos_payments by terminal_id + auth code, then commit the
        // matched tenders as reconciled. Parser dispatched by bank_id.
        Route::post('bank-reconciliation/preview', [BankReconciliationController::class, 'preview'])
            ->name('bank-reconciliation.preview');
        Route::post('bank-reconciliation/commit', [BankReconciliationController::class, 'commit'])
            ->name('bank-reconciliation.commit');

        // P-F7 — Pending Reconciliation approval queue: orders whose
        // force-recorded Soft POS tenders await the daily admin review.
        // Approve fires the DEFERRED money effects (commission split +
        // charity round-up forwarding); reject marks the money as never
        // arrived. settings.manage gated, like bank-reconciliation.
        Route::get('pending-reconciliation', [PendingReconciliationController::class, 'index'])
            ->name('pending-reconciliation.index');
        Route::post('pending-reconciliation/approve', [PendingReconciliationController::class, 'approve'])
            ->name('pending-reconciliation.approve');
        Route::post('pending-reconciliation/reject', [PendingReconciliationController::class, 'reject'])
            ->name('pending-reconciliation.reject');

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

        // Marketing — admin-driven advertiser onboarding. The `advertisers`
        // row lives in the shared charity_db (owned by the marketing-api app);
        // pos_admin creates the account + login, links it to a merchant,
        // suspends, and resets the password. Gated by
        // marketing.advertisers.manage via AdvertiserPolicy. {advertiser}
        // binds by id.
        Route::get('marketing/advertisers', [MarketingAdvertisersController::class, 'index'])
            ->name('marketing.advertisers.index');
        Route::post('marketing/advertisers', [MarketingAdvertisersController::class, 'store'])
            ->name('marketing.advertisers.store');
        // Onboard a NEW advertising-only company + its portal login in one step
        // (the wizard for advertisers who aren't existing POS merchants).
        // Declared before the {advertiser} routes so "with-company" isn't read
        // as an id.
        Route::post('marketing/advertisers/with-company', [MarketingAdvertisersController::class, 'storeWithCompany'])
            ->name('marketing.advertisers.store-with-company');
        Route::get('marketing/advertisers/{advertiser}', [MarketingAdvertisersController::class, 'show'])
            ->name('marketing.advertisers.show');
        Route::patch('marketing/advertisers/{advertiser}', [MarketingAdvertisersController::class, 'update'])
            ->name('marketing.advertisers.update');
        // Edit the advertiser's advertising-only company (CR / owners / activities).
        Route::patch('marketing/advertisers/{advertiser}/company', [MarketingAdvertisersController::class, 'updateCompany'])
            ->name('marketing.advertisers.company.update');
        Route::patch('marketing/advertisers/{advertiser}/activities', [MarketingAdvertisersController::class, 'syncCompanyActivities'])
            ->name('marketing.advertisers.activities.update');
        Route::post('marketing/advertisers/{advertiser}/reset-password', [MarketingAdvertisersController::class, 'resetPassword'])
            ->name('marketing.advertisers.reset-password');

        // Marketing — content review. Advertisers submit content (pending) on
        // the marketing portal; the admin approves (eligible for sliders) or
        // rejects with a note. content_assets is marketing-api-owned; pos_admin
        // writes only the review fields. Gated by marketing.content.review.
        Route::get('marketing/content/submitters', [MarketingContentController::class, 'submitters'])
            ->name('marketing.content.submitters');
        Route::get('marketing/content', [MarketingContentController::class, 'index'])
            ->name('marketing.content.index');
        Route::post('marketing/content/{contentAsset}/approve', [MarketingContentController::class, 'approve'])
            ->name('marketing.content.approve');
        Route::post('marketing/content/{contentAsset}/reject', [MarketingContentController::class, 'reject'])
            ->name('marketing.content.reject');

        // Marketing — slider builder. Group approved content into an ordered
        // loop + target branches/devices. `options` MUST precede the {slider}
        // route so it isn't read as a uuid. Gated by marketing.sliders.manage.
        Route::get('marketing/sliders', [MarketingSlidersController::class, 'index'])
            ->name('marketing.sliders.index');
        Route::get('marketing/sliders/options', [MarketingSlidersController::class, 'options'])
            ->name('marketing.sliders.options');
        Route::post('marketing/sliders/check-conflicts', [MarketingSlidersController::class, 'checkConflicts'])
            ->name('marketing.sliders.check-conflicts');
        Route::post('marketing/sliders', [MarketingSlidersController::class, 'store'])
            ->name('marketing.sliders.store');
        Route::get('marketing/sliders/{slider:uuid}', [MarketingSlidersController::class, 'show'])
            ->name('marketing.sliders.show');
        Route::get('marketing/sliders/{slider:uuid}/audience', [MarketingSlidersController::class, 'audience'])
            ->name('marketing.sliders.audience');
        Route::patch('marketing/sliders/{slider:uuid}', [MarketingSlidersController::class, 'update'])
            ->name('marketing.sliders.update');
        Route::delete('marketing/sliders/{slider:uuid}', [MarketingSlidersController::class, 'destroy'])
            ->name('marketing.sliders.destroy');

        // Marketing — admin uploads media straight into a slider (forwarded to
        // marketing-api's shared content store). Gated by sliders.manage.
        Route::post('marketing/content/upload', [MarketingContentUploadController::class, 'upload'])
            ->name('marketing.content.upload');
        Route::post('marketing/content/{assetId}/replace', [MarketingContentUploadController::class, 'replace'])
            ->name('marketing.content.replace');

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

            // Per-merchant commission profile — the platform's revenue
            // split for this merchant's sales (POS-owned, distinct from
            // the charity round-up commission_profiles). show returns a
            // default 100%-merchant shape when none is configured yet;
            // update replaces the share lines + recomputes the residual.
            Route::get('merchants/{merchant:uuid}/commission-profile',
                [MerchantCommissionProfileController::class, 'show'])
                ->name('merchants.commission-profile.show');
            Route::put('merchants/{merchant:uuid}/commission-profile',
                [MerchantCommissionProfileController::class, 'update'])
                ->name('merchants.commission-profile.update');

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
            // Edit a device's identity + catalogue + commission/organization
            // bindings (vanilla field update — assign/unassign/decommission
            // handle the workflow-side-effect changes). Gated by DevicesRegister.
            Route::patch('devices/{device:uuid}', [DevicesController::class, 'update'])->name('devices.update');
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

            // Scalefusion (MDM) live detail + remote control for the
            // device behind this row, joined by kiosk_id. Read is gated
            // by DevicesView; every control action by DevicesControl +
            // audited. HTTP + encoding live in ScalefusionService.
            Route::get('devices/{device:uuid}/scalefusion', [DeviceScalefusionController::class, 'show'])->name('devices.scalefusion.show');
            Route::get('devices/{device:uuid}/scalefusion/locations', [DeviceScalefusionController::class, 'locations'])->name('devices.scalefusion.locations');
            Route::post('devices/{device:uuid}/scalefusion/reboot', [DeviceScalefusionController::class, 'reboot'])->name('devices.scalefusion.reboot');
            Route::post('devices/{device:uuid}/scalefusion/alarm', [DeviceScalefusionController::class, 'alarm'])->name('devices.scalefusion.alarm');
            Route::post('devices/{device:uuid}/scalefusion/lock', [DeviceScalefusionController::class, 'lock'])->name('devices.scalefusion.lock');
            Route::post('devices/{device:uuid}/scalefusion/unlock', [DeviceScalefusionController::class, 'unlock'])->name('devices.scalefusion.unlock');
            Route::post('devices/{device:uuid}/scalefusion/clear-app-data', [DeviceScalefusionController::class, 'clearAppData'])->name('devices.scalefusion.clear-app-data');
            Route::post('devices/{device:uuid}/scalefusion/action', [DeviceScalefusionController::class, 'action'])->name('devices.scalefusion.action');
            Route::post('devices/{device:uuid}/scalefusion/broadcast-message', [DeviceScalefusionController::class, 'broadcastMessage'])->name('devices.scalefusion.broadcast-message');
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

        // Platform-wide Sales / Orders viewer -- every merchant's orders,
        // filterable by date + company. reports.view gated.
        Route::get('orders', [OrdersController::class, 'index'])
            ->name('orders.index');
        // The Sales-tab drill: merchants -> branches with per-method totals,
        // the entry point to the per-terminal verification workspace.
        Route::get('orders/summary', [OrdersController::class, 'summary'])
            ->name('orders.summary');

        // Platform sales report (aggregates + graphs). No company_uuid →
        // platform-wide (dashboard); with one → per-merchant Sales tab.
        // reports.view gated.
        Route::get('sales-report', SalesReportController::class)
            ->name('sales-report');
        // v2 #17 — platform settlement: per-merchant commission breakdown +
        // platform totals over a window. No company_uuid → all merchants; with
        // one → that merchant only. reports.view gated.
        Route::get('settlement-report', SettlementReportController::class)
            ->name('settlement-report');
        // v2 #18 — platform round-up donation report (charity raised across
        // merchants + per-merchant breakdown). reports.view gated.
        Route::get('roundup-report', RoundUpReportController::class)
            ->name('roundup-report');

        // v2 #17 Phase B — merchant payouts (the stateful settlement workflow).
        // Read on reports.view; create/mark-paid/cancel on settings.manage.
        Route::get('payouts', [PayoutsController::class, 'index'])->name('payouts.index');
        Route::get('payouts/{payout:uuid}/lines', [PayoutsController::class, 'lines'])->name('payouts.lines');
        Route::post('payouts', [PayoutsController::class, 'store'])->name('payouts.store');
        Route::post('payouts/batch-mark-paid', [PayoutsController::class, 'batchMarkPaid'])->name('payouts.batch-mark-paid');
        Route::post('payouts/{payout:uuid}/mark-paid', [PayoutsController::class, 'markPaid'])->name('payouts.mark-paid');
        Route::post('payouts/{payout:uuid}/cancel', [PayoutsController::class, 'cancel'])->name('payouts.cancel');

        // Commission settlement — reconcile card sales against the bank's ACTUAL
        // fee and finalise the exact merchant net (estimate → settled). Read on
        // reports.view; preview/apply/reverse on settings.manage.
        Route::get('commission-settlements', [CommissionSettlementController::class, 'index'])->name('commission-settlements.index');
        Route::get('commission-settlements/pending', [CommissionSettlementController::class, 'pending'])->name('commission-settlements.pending');
        Route::get('commission-settlements/preview', [CommissionSettlementController::class, 'preview'])->name('commission-settlements.preview');
        Route::get('commission-settlements/orders', [CommissionSettlementController::class, 'orders'])->name('commission-settlements.orders');
        Route::post('commission-settlements/orders', [CommissionSettlementController::class, 'settleOrders'])->name('commission-settlements.settle-orders');
        Route::post('commission-settlements', [CommissionSettlementController::class, 'store'])->name('commission-settlements.store');
        Route::post('commission-settlements/{settlement:uuid}/reverse', [CommissionSettlementController::class, 'reverse'])->name('commission-settlements.reverse');

        // Phase B — commission INVOICES (merchant owes the platform for cash/
        // bank_pos sales; the reverse of payouts). Read on reports.view; issue/
        // mark-paid/void on settings.manage.
        Route::get('commission-invoices', [CommissionInvoicesController::class, 'index'])->name('commission-invoices.index');
        Route::get('commission-invoices/pending', [CommissionInvoicesController::class, 'pendingList'])->name('commission-invoices.pending');
        Route::get('commission-invoices/{invoice:uuid}/lines', [CommissionInvoicesController::class, 'lines'])->name('commission-invoices.lines');
        Route::post('commission-invoices', [CommissionInvoicesController::class, 'store'])->name('commission-invoices.store');
        Route::post('commission-invoices/batch-mark-paid', [CommissionInvoicesController::class, 'batchMarkPaid'])->name('commission-invoices.batch-mark-paid');
        Route::post('commission-invoices/{invoice:uuid}/mark-paid', [CommissionInvoicesController::class, 'markPaid'])->name('commission-invoices.mark-paid');
        Route::post('commission-invoices/{invoice:uuid}/void', [CommissionInvoicesController::class, 'void'])->name('commission-invoices.void');
    });
