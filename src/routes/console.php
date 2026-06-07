<?php

declare(strict_types=1);

use App\Jobs\Admin\ScanExpiringCompanyDocumentsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new ScanExpiringCompanyDocumentsJob)
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->name('scan-expiring-company-documents');
