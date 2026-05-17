<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Admin\BusinessActivitiesController;
use App\Http\Controllers\Api\Admin\MerchantActivitiesController;
use App\Http\Controllers\Api\Admin\MerchantDocumentVerificationController;
use App\Http\Controllers\Api\Admin\MerchantDocumentsController;
use App\Http\Controllers\Api\Admin\MerchantStatusController;
use App\Http\Controllers\Api\Admin\MerchantsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'pos.admin.session', 'pos.tenant'])
    ->prefix('admin/api/v1')
    ->name('admin.api.v1.')
    ->group(function (): void {
        Route::get('business-activities', [BusinessActivitiesController::class, 'index'])
            ->name('business-activities.index');

        Route::get('merchants', [MerchantsController::class, 'index'])->name('merchants.index');
        Route::post('merchants', [MerchantsController::class, 'store'])->name('merchants.store');

        Route::scopeBindings()->group(function (): void {
            Route::get('merchants/{merchant:uuid}', [MerchantsController::class, 'show'])->name('merchants.show');
            Route::patch('merchants/{merchant:uuid}', [MerchantsController::class, 'update'])->name('merchants.update');

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
        });
    });
