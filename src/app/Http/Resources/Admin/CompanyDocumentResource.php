<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\CompanyDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CompanyDocument
 */
class CompanyDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'company_id' => $this->company_id,
            'document_type' => $this->document_type?->value,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'sha256' => $this->sha256,
            'verification_status' => $this->verification_status?->value,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'verified_by_user_id' => $this->verified_by_user_id,
            'uploaded_by_user_id' => $this->uploaded_by_user_id,
            'rejection_reason' => $this->rejection_reason,
            'issued_at' => $this->issued_at?->toDateString(),
            'expires_at' => $this->expires_at?->toDateString(),
            'days_until_expiry' => $this->daysUntilExpiry(),
            'is_expired' => $this->isExpired(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
