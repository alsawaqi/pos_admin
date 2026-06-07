<?php

declare(strict_types=1);

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\AuditLog;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

it('rejects updates to audit log rows', function (): void {
    $log = (new WriteAuditLogAction)->handle(new AuditLogData(
        event: 'company.created',
        companyId: Company::factory()->create()->id,
    ));

    $log->event = 'tampered.event';

    expect(fn () => $log->save())->toThrow(RuntimeException::class);
});

it('rejects deletes of audit log rows', function (): void {
    $log = (new WriteAuditLogAction)->handle(new AuditLogData(
        event: 'company.created',
        companyId: Company::factory()->create()->id,
    ));

    expect(fn () => $log->delete())->toThrow(RuntimeException::class);
    $this->assertDatabaseHas(AuditLog::class, ['id' => $log->id]);
});

it('captures request ip and user agent automatically when not provided', function (): void {
    $company = Company::factory()->create();

    $request = Request::create('/internal-test', 'GET', server: [
        'REMOTE_ADDR' => '203.0.113.7',
        'HTTP_USER_AGENT' => 'Mithqal-Test-Agent',
    ]);
    $this->app->instance('request', $request);

    $log = (new WriteAuditLogAction)->handle(new AuditLogData(
        event: 'merchant.viewed',
        companyId: $company->id,
    ));

    expect($log->ip_address)->toBe('203.0.113.7');
    expect($log->user_agent)->toBe('Mithqal-Test-Agent');
});
