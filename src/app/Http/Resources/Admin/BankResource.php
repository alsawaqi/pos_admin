<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Bank;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lean projection of a {@see Bank} for the Register Device dropdown
 * and the Device Show page.
 *
 * Only exposes the fields the POS admin actually renders (id, name,
 * short_name, swift_code, is_active). The production charity table
 * has more columns (country_id, branch_name, phone, email, …) — we
 * deliberately do not expose them here because (a) the POS app
 * doesn't render them and (b) keeping the payload tight makes the
 * dropdown response fast even when the bank list grows.
 *
 * @mixin Bank
 */
class BankResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // short_name is what the dropdown displays in space-
            // constrained contexts (e.g. fleet list cell) — fall
            // back to name in the front-end when it's null.
            'short_name' => $this->short_name,
            'swift_code' => $this->swift_code,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
