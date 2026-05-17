<?php

declare(strict_types=1);

use App\Support\TenantContext;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

uses()
    ->beforeEach(function (): void {
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    })
    ->in('Feature');
