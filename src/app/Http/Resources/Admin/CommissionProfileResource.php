<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\CommissionProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lean projection of a {@see CommissionProfile} for the dropdown.
 * Only exposes the fields the Register Device form needs (id + name
 * + active flag) — description is omitted so the list payload stays
 * tight, even though the model carries it.
 *
 * @mixin CommissionProfile
 */
class CommissionProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
