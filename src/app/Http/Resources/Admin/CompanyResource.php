<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Company
 */
class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'name_ar' => $this->name_ar,
            'legal_name' => $this->legal_name,
            'legal_name_ar' => $this->legal_name_ar,
            'cr_number' => $this->cr_number,
            'vat_number' => $this->vat_number,
            'cr_expiry_date' => $this->cr_expiry_date?->toDateString(),
            'contact' => [
                'name' => $this->contact_name,
                'phone' => $this->contact_phone,
                'email' => $this->contact_email,
            ],
            'status' => $this->status?->value,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'default_currency' => $this->default_currency,
            'default_locale' => $this->default_locale,
            'branches_count' => $this->whenCounted('branches'),
            'devices_count' => $this->whenCounted('devices'),
            'documents_count' => $this->whenCounted('documents'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
