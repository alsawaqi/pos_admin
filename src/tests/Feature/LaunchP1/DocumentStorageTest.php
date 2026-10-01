<?php

declare(strict_types=1);

/*
 * LAUNCH-P1 P1-1 (document upload crash) and the low finding
 * "deleting a document is not audited and destroys the file".
 */

require_once __DIR__.'/../../Support/launch-p1-helpers.php';

use App\Enums\PlatformRole;
use App\Models\Company;
use App\Models\CompanyDocument;
use Database\Seeders\PlatformRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(PlatformRoleSeeder::class);
});

/**
 * Evaluate config/filesystems.php the way a fresh production boot would
 * when the operator never set DOCUMENTS_DISK_DRIVER / DOCUMENTS_LOCAL_ROOT.
 *
 * @return array<string, mixed>
 */
function p1DocumentsDiskWithoutEnv(): array
{
    $saved = [];
    foreach (['DOCUMENTS_DISK_DRIVER', 'DOCUMENTS_LOCAL_ROOT'] as $key) {
        $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }

    try {
        $config = require config_path('filesystems.php');
    } finally {
        foreach ($saved as $key => [$env, $server, $put]) {
            if ($env !== null) {
                $_ENV[$key] = $env;
            }
            if ($server !== null) {
                $_SERVER[$key] = $server;
            }
            if ($put !== false) {
                putenv("{$key}={$put}");
            }
        }
    }

    return $config['disks']['documents'];
}

it('defaults the documents disk to the private local driver when DOCUMENTS_DISK_DRIVER is not set', function (): void {
    $disk = p1DocumentsDiskWithoutEnv();

    expect($disk['driver'])->toBe('local')
        ->and($disk['visibility'])->toBe('private')
        ->and(str_replace('\\', '/', $disk['root']))->toEndWith('storage/app/private/documents');
});

it('uploads a merchant document over HTTP with no DOCUMENTS_DISK_DRIVER configured', function (): void {
    $root = storage_path('framework/testing/p1-documents-'.bin2hex(random_bytes(4)));
    config(['filesystems.disks.documents' => array_merge(p1DocumentsDiskWithoutEnv(), ['root' => $root])]);
    Storage::forgetDisk('documents');

    p1ActingAs($this, PlatformRole::OnboardingOfficer->value);
    $company = Company::factory()->create();

    try {
        $response = $this->post("/admin/api/v1/merchants/{$company->uuid}/documents", [
            'document_type' => 'cr_certificate',
            'file' => UploadedFile::fake()->create('cr.pdf', 300, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonPath('data.document_type', 'cr_certificate');

        $document = CompanyDocument::query()->firstOrFail();
        expect($document->disk)->toBe('documents')
            ->and(is_file($root.'/'.$document->path))->toBeTrue();
    } finally {
        File::deleteDirectory($root);
    }
});

it('answers a storage failure with a readable JSON error and stores nothing', function (): void {
    // A regular FILE where the disk root directory should be: every
    // write fails the way a full or read-only volume would.
    $blocker = storage_path('framework/testing/p1-not-a-directory-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists(dirname($blocker));
    file_put_contents($blocker, 'x');
    config(['filesystems.disks.documents' => array_merge(p1DocumentsDiskWithoutEnv(), ['root' => $blocker])]);
    Storage::forgetDisk('documents');

    p1ActingAs($this, PlatformRole::OnboardingOfficer->value);
    $company = Company::factory()->create();

    try {
        $this->post("/admin/api/v1/merchants/{$company->uuid}/documents", [
            'document_type' => 'owner_id_card',
            'file' => UploadedFile::fake()->create('id.jpg', 200, 'image/jpeg'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(500)
            ->assertJsonPath('code', 'document_storage_failed')
            ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains((string) $m, 'could not be saved'))->etc());

        expect(CompanyDocument::query()->count())->toBe(0);
    } finally {
        @unlink($blocker);
    }
});

it('explains a file the server refused instead of a generic validation line', function (): void {
    p1ActingAs($this, PlatformRole::OnboardingOfficer->value);
    $company = Company::factory()->create();
    Storage::fake('documents');

    $this->post("/admin/api/v1/merchants/{$company->uuid}/documents", [
        'document_type' => 'cr_certificate',
        'file' => UploadedFile::fake()->create('huge.pdf', 11 * 1024, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('errors.file.0', 'The file is larger than 10 MB. Please upload a smaller scan or photo.');
});

it('soft-deletes a document, keeps the stored file and writes an audit row', function (): void {
    Storage::fake('documents');
    $actor = p1ActingAs($this, PlatformRole::SuperAdmin->value);
    $company = Company::factory()->create();

    $this->post("/admin/api/v1/merchants/{$company->uuid}/documents", [
        'document_type' => 'cr_certificate',
        'file' => UploadedFile::fake()->create('cr.pdf', 50, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();

    $document = CompanyDocument::query()->firstOrFail();
    Storage::disk('documents')->assertExists($document->path);

    $this->deleteJson("/admin/api/v1/merchants/{$company->uuid}/documents/{$document->uuid}")
        ->assertNoContent();

    // Gone from the admin list, but the row and the file are kept.
    $this->getJson("/admin/api/v1/merchants/{$company->uuid}/documents")
        ->assertOk()->assertJsonCount(0, 'data');
    expect(CompanyDocument::withTrashed()->find($document->id)?->trashed())->toBeTrue();
    Storage::disk('documents')->assertExists($document->path);

    $this->assertDatabaseHas('pos_audit_logs', [
        'event' => 'company.document.deleted',
        'actor_user_id' => $actor->id,
        'company_id' => $company->id,
        'auditable_id' => $document->id,
    ]);
});
