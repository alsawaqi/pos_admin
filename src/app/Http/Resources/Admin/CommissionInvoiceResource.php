<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\CommissionInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CommissionInvoice
 */
class CommissionInvoiceResource extends JsonResource
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
            'branch_id' => $this->branch_id !== null ? (int) $this->branch_id : null,
            // Present when the index join selects them (else null).
            'company_uuid' => $this->company_uuid ?? null,
            'company_name' => $this->company_name ?? null,
            'branch_name' => $this->branch_name ?? null,
            'period_from' => $this->period_from?->toIso8601String(),
            'period_to' => $this->period_to?->toIso8601String(),
            'status' => $this->status,
            // decimal:3 casts → strings, preserving OMR precision.
            'gross_amount' => (string) $this->gross_amount,
            'cash_gross' => (string) $this->cash_gross,
            'bank_pos_gross' => (string) $this->bank_pos_gross,
            'platform_amount' => (string) $this->platform_amount,
            'other_amount' => (string) $this->other_amount,
            'merchant_amount' => (string) $this->merchant_amount,
            'total_owed' => (string) $this->total_owed,
            'sales_count' => (int) $this->sales_count,
            'reference' => $this->reference,
            'note' => $this->note,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
