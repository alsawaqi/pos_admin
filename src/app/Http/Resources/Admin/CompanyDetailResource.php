<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Company
 */
class CompanyDetailResource extends JsonResource
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
            'compliance' => [
                'cr_number' => $this->cr_number,
                'cr_issue_date' => $this->cr_issue_date?->toDateString(),
                'cr_expiry_date' => $this->cr_expiry_date?->toDateString(),
                'establishment_date' => $this->establishment_date?->toDateString(),
                'tax_number' => $this->tax_number,
                'vat_number' => $this->vat_number,
                'vat_registered_at' => $this->vat_registered_at?->toDateString(),
                'chamber_of_commerce_number' => $this->chamber_of_commerce_number,
                'municipality_license_number' => $this->municipality_license_number,
            ],
            'contact' => [
                'name' => $this->contact_name,
                'phone' => $this->contact_phone,
                'email' => $this->contact_email,
            ],
            // Owners is now a list (was a single object). The Vue
            // Show page renders them as cards; the primary one is
            // surfaced first because the Company::owners() relation
            // orders by is_primary DESC. `whenLoaded` keeps the list
            // endpoint payload light when only the index resource
            // calls this projection without preloading owners.
            'owners' => $this->whenLoaded('owners', fn (): array => $this->owners
                ->map(fn ($owner): array => [
                    'id' => $owner->id,
                    'full_name_en' => $owner->full_name_en,
                    'full_name_ar' => $owner->full_name_ar,
                    'civil_id' => $owner->civil_id,
                    'nationality' => $owner->nationality,
                    'phone' => $owner->phone,
                    'email' => $owner->email,
                    'is_primary' => (bool) $owner->is_primary,
                    'ownership_percentage' => $owner->ownership_percentage !== null
                        ? (float) $owner->ownership_percentage
                        : null,
                ])
                ->all(), []),
            'is_advertiser_only' => (bool) $this->is_advertiser_only,
            'status' => $this->status?->value,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'suspension_reason' => $this->suspension_reason,
            'default_currency' => $this->default_currency,
            'default_locale' => $this->default_locale,
            'settings' => $this->settings,
            'notes' => $this->notes,
            'activities' => BusinessActivityResource::collection($this->whenLoaded('activities')),
            'documents' => CompanyDocumentResource::collection($this->whenLoaded('documents')),
            'status_history' => CompanyStatusHistoryResource::collection($this->whenLoaded('statusHistory')),
            // Counts drive the Portal Users tab's "+ Invite" button
            // gate (blueprint §4.5 requires ≥1 branch + ≥1 device
            // before the first portal user can be invited). They're
            // emitted only when the controller pre-loaded them via
            // ->loadCount(['branches', 'devices']) — see
            // MerchantsController::show / store / update.
            'branches_count' => $this->whenCounted('branches'),
            'devices_count' => $this->whenCounted('devices'),
            'onboarded_by_user_id' => $this->onboarded_by_user_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
