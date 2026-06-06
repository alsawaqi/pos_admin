<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\CommissionPartyType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates PUT /admin/api/v1/merchants/{merchant}/commission-profile.
 *
 * The admin types the non-merchant share lines (platform / bank / other);
 * the merchant takes the residual, so the only cross-field rule is that
 * Σ(share percents) must not exceed 100. An empty `shares` array is valid
 * and means the merchant keeps 100% (no split recorded at sale time).
 */
class UpsertMerchantCommissionProfileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['sometimes', 'boolean'],
            'shares' => ['present', 'array'],
            'shares.*.party_type' => ['required', 'string', Rule::in(CommissionPartyType::shareValues())],
            'shares.*.label' => ['required', 'string', 'max:120'],
            'shares.*.percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array{percent?: mixed}> $shares */
            $shares = (array) $this->input('shares', []);

            $sum = 0.0;
            foreach ($shares as $share) {
                $sum += (float) ($share['percent'] ?? 0);
            }

            // Round to dodge float drift (e.g. 2.00 + 98.00 reading 100.0000001).
            if (round($sum, 2) > 100) {
                $validator->errors()->add(
                    'shares',
                    'The combined commission of all parties cannot exceed 100%. The merchant takes the remainder.',
                );
            }
        });
    }
}
