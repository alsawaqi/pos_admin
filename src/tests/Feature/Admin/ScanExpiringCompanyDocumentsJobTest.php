<?php

declare(strict_types=1);

use App\Enums\DocumentVerificationStatus;
use App\Jobs\Admin\ScanExpiringCompanyDocumentsJob;
use App\Models\AuditLog;
use App\Models\CompanyDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('flips expired documents to the expired status and writes an audit entry', function (): void {
    $expired = CompanyDocument::factory()->verified()->create([
        'expires_at' => now()->subDays(2)->toDateString(),
    ]);
    $upcoming = CompanyDocument::factory()->verified()->create([
        'expires_at' => now()->addDays(40)->toDateString(),
    ]);

    app(ScanExpiringCompanyDocumentsJob::class)->handle(app(\App\Actions\Security\WriteAuditLogAction::class));

    expect($expired->refresh()->verification_status)->toBe(DocumentVerificationStatus::Expired)
        ->and($upcoming->refresh()->verification_status)->toBe(DocumentVerificationStatus::Verified);

    $this->assertDatabaseHas(AuditLog::class, [
        'event' => 'company.document.expired',
        'auditable_type' => CompanyDocument::class,
        'auditable_id' => $expired->id,
    ]);
});

it('does not re-mark documents that are already expired', function (): void {
    $document = CompanyDocument::factory()->create([
        'verification_status' => DocumentVerificationStatus::Expired,
        'expires_at' => now()->subDays(5)->toDateString(),
    ]);

    app(ScanExpiringCompanyDocumentsJob::class)->handle(app(\App\Actions\Security\WriteAuditLogAction::class));

    expect(AuditLog::query()
        ->where('event', 'company.document.expired')
        ->where('auditable_id', $document->id)
        ->count())->toBe(0);
});
