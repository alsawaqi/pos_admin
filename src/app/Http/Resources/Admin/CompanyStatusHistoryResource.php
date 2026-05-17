<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\CompanyStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CompanyStatusHistory
 */
class CompanyStatusHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_status' => $this->from_status?->value,
            'to_status' => $this->to_status->value,
            'changed_by_user_id' => $this->changed_by_user_id,
            'reason' => $this->reason,
            'metadata' => $this->metadata,
            'changed_at' => $this->changed_at?->toIso8601String(),
        ];
    }
}
