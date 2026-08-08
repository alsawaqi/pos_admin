<?php

declare(strict_types=1);

use App\Actions\Security\WriteAuditLogAction;
use App\Enums\DocumentVerificationStatus;
use App\Jobs\Admin\ScanExpiringCompanyDocumentsJob;
use App\Models\AuditLog;
use App\Models\CompanyDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('flips expired documents to the expired status and writes an audit entry', function (): void {
    $expired = CompanyDocument::factory()->verified()->create([
        'expires_at' => now()->subDays(2)->toDateString(),
    ]);
    $upcoming = CompanyDocument::factory()->verified()->create([
        'expires_at' => now()->addDays(40)->toDateString(),
    ]);

    $job = app(ScanExpiringCompanyDocumentsJob::class);
    $writeAuditLog = app(WriteAuditLogAction::class);

    $job->handle($writeAuditLog);
    $job->handle($writeAuditLog);

    expect($expired->refresh()->verification_status)->toBe(DocumentVerificationStatus::Expired)
        ->and($upcoming->refresh()->verification_status)->toBe(DocumentVerificationStatus::Verified);

    $this->assertDatabaseHas(AuditLog::class, [
        'event' => 'company.document.expired',
        'auditable_type' => CompanyDocument::class,
        'auditable_id' => $expired->id,
    ]);

    expect(AuditLog::query()
        ->where('event', 'company.document.expired')
        ->where('auditable_type', CompanyDocument::class)
        ->where('auditable_id', $expired->id)
        ->count())->toBe(1);
});

it('does not re-mark documents that are already expired', function (): void {
    $document = CompanyDocument::factory()->create([
        'verification_status' => DocumentVerificationStatus::Expired,
        'expires_at' => now()->subDays(5)->toDateString(),
    ]);

    app(ScanExpiringCompanyDocumentsJob::class)->handle(app(WriteAuditLogAction::class));

    expect(AuditLog::query()
        ->where('event', 'company.document.expired')
        ->where('auditable_id', $document->id)
        ->count())->toBe(0);
});

it('rolls back the expired status when writing its audit entry fails', function (): void {
    $document = CompanyDocument::factory()->verified()->create([
        'expires_at' => now()->subDays(2)->toDateString(),
    ]);

    $auditCreatingEvent = 'eloquent.creating: '.AuditLog::class;

    Event::listen($auditCreatingEvent, static function (): never {
        throw new RuntimeException('Forced audit write failure.');
    });

    try {
        expect(fn () => app(ScanExpiringCompanyDocumentsJob::class)
            ->handle(app(WriteAuditLogAction::class)))
            ->toThrow(RuntimeException::class, 'Forced audit write failure.');
    } finally {
        Event::forget($auditCreatingEvent);
    }

    expect($document->refresh()->verification_status)->toBe(DocumentVerificationStatus::Verified);

    $this->assertDatabaseMissing(AuditLog::class, [
        'event' => 'company.document.expired',
        'auditable_type' => CompanyDocument::class,
        'auditable_id' => $document->id,
    ]);
});
