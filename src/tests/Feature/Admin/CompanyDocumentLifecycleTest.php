<?php

declare(strict_types=1);

use App\Actions\Admin\RejectCompanyDocumentAction;
use App\Actions\Admin\UploadCompanyDocumentAction;
use App\Actions\Admin\VerifyCompanyDocumentAction;
use App\Data\Admin\RejectCompanyDocumentData;
use App\Data\Admin\UploadCompanyDocumentData;
use App\Data\Admin\VerifyCompanyDocumentData;
use App\Enums\DocumentType;
use App\Enums\DocumentVerificationStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('documents');
});

it('persists an uploaded document on the documents disk with a sha256 fingerprint', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    $file = UploadedFile::fake()->create('cr.pdf', 100, 'application/pdf');

    $document = app(UploadCompanyDocumentAction::class)->handle(
        $company,
        new UploadCompanyDocumentData(
            documentType: DocumentType::CrCertificate,
            file: $file,
            issuedAt: '2025-01-01',
            expiresAt: '2027-01-01',
        ),
        $actor,
    );

    expect($document->verification_status)->toBe(DocumentVerificationStatus::Pending)
        ->and($document->disk)->toBe('documents')
        ->and($document->mime_type)->toBe('application/pdf')
        ->and($document->sha256)->toHaveLength(64)
        ->and($document->uploaded_by_user_id)->toBe($actor->id);

    Storage::disk('documents')->assertExists($document->path);

    $this->assertDatabaseHas(AuditLog::class, [
        'event' => 'company.document.uploaded',
        'company_id' => $company->id,
    ]);
});

it('verifies a pending document and stamps the verifier', function (): void {
    $document = CompanyDocument::factory()->create();
    $actor = User::factory()->create();

    $verified = app(VerifyCompanyDocumentAction::class)->handle(
        $document,
        new VerifyCompanyDocumentData(notes: 'Looks good'),
        $actor,
    );

    expect($verified->verification_status)->toBe(DocumentVerificationStatus::Verified)
        ->and($verified->verified_by_user_id)->toBe($actor->id)
        ->and($verified->verified_at)->not->toBeNull()
        ->and($verified->notes)->toBe('Looks good');
});

it('refuses to verify an already-verified document', function (): void {
    $document = CompanyDocument::factory()->verified()->create();

    expect(fn () => app(VerifyCompanyDocumentAction::class)->handle(
        $document,
        new VerifyCompanyDocumentData,
    ))->toThrow(DomainException::class);
});

it('rejects a document and records the reason', function (): void {
    $document = CompanyDocument::factory()->create();
    $actor = User::factory()->create();

    $rejected = app(RejectCompanyDocumentAction::class)->handle(
        $document,
        new RejectCompanyDocumentData(reason: 'Unreadable scan'),
        $actor,
    );

    expect($rejected->verification_status)->toBe(DocumentVerificationStatus::Rejected)
        ->and($rejected->rejection_reason)->toBe('Unreadable scan');
});
