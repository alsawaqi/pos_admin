<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\BusinessActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BusinessActivity
 */
class BusinessActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name_en' => $this->name_en,
            'name_ar' => $this->name_ar,
            'category' => $this->category?->value,
            'isic_code' => $this->isic_code,
            'description_en' => $this->description_en,
            'description_ar' => $this->description_ar,
            'is_active' => $this->is_active,
            'display_order' => $this->display_order,
            'is_primary' => $this->whenPivotLoaded('pos_admin_company_activities', fn (): ?bool => (bool) $this->pivot?->is_primary),
        ];
    }
}
