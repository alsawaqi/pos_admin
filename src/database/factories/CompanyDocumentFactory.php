<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Enums\DocumentVerificationStatus;
use App\Models\Company;
use App\Models\CompanyDocument;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CompanyDocument>
 */
class CompanyDocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(DocumentType::cases());

        return [
            'uuid' => (string) Str::uuid(),
            'company_id' => Company::factory(),
            'document_type' => $type,
            'disk' => 'documents',
            'path' => 'companies/'.fake()->uuid().'/'.Str::random(40).'.pdf',
            'original_name' => $type->value.'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(50_000, 5_000_000),
            'sha256' => hash('sha256', Str::random(64)),
            'verification_status' => DocumentVerificationStatus::Pending,
            'issued_at' => fake()->dateTimeBetween('-2 years', '-1 month'),
            'expires_at' => $type->tracksExpiry() ? fake()->dateTimeBetween('+1 month', '+5 years') : null,
        ];
    }

    public function verified(): self
    {
        return $this->state(fn (): array => [
            'verification_status' => DocumentVerificationStatus::Verified,
            'verified_at' => now(),
        ]);
    }

    public function rejected(): self
    {
        return $this->state(fn (): array => [
            'verification_status' => DocumentVerificationStatus::Rejected,
            'rejection_reason' => 'Unreadable scan',
        ]);
    }

    public function expiringIn(int $days): self
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->addDays($days),
        ]);
    }
}
