<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->name('api.v1.')
    ->group(function (): void {
        Route::get('/health', fn () => response()->json([
            'status' => 'ok',
            'service' => 'pos-admin',
        ]))->name('health');
    });
