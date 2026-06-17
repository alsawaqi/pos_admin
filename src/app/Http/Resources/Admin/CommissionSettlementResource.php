<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\CommissionSettlement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CommissionSettlement
 */
class CommissionSettlementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'company_id' => (int) $this->company_id,
            // Present when the index join selects them (else null).
            'company_uuid' => $this->company_uuid ?? null,
            'company_name' => $this->company_name ?? null,
            'branch_id' => $this->branch_id !== null ? (int) $this->branch_id : null,
            'source' => $this->source,
            'bank_id' => $this->bank_id !== null ? (int) $this->bank_id : null,
            'statement_date' => $this->statement_date?->toDateString(),
            'period_from' => $this->period_from?->toIso8601String(),
            'period_to' => $this->period_to?->toIso8601String(),
            // decimal:3 casts → strings, preserving OMR precision.
            'card_gross' => (string) $this->card_gross,
            'estimated_bank' => (string) $this->estimated_bank,
            'actual_bank' => (string) $this->actual_bank,
            'platform_total' => (string) $this->platform_total,
            'merchant_net' => (string) $this->merchant_net,
            'variance' => (string) $this->variance,
            'orders_count' => (int) $this->orders_count,
            'status' => $this->status,
            'note' => $this->note,
            'reversed_at' => $this->reversed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
