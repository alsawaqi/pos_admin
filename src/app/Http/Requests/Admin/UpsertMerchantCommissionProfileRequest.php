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
 * The admin types the non-merchant share lines (platform / bank / other),
 * each optionally scoped to a tender CHANNEL (all | card | cash_bank); the
 * merchant takes the residual per channel. The cross-field rule is therefore
 * per channel: the lines that bite CARD sales (bank + all + card-scoped) must
 * sum ≤ 100, and the lines that bite CASH/BANK-POS sales (all + cash_bank-
 * scoped, never bank) must sum ≤ 100 — the same percent can never double-bite
 * one sale, so the two channels validate independently. An empty `shares`
 * array is valid and means the merchant keeps 100% everywhere.
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
            'shares.*.applies_to' => ['nullable', 'string', Rule::in(['all', 'card', 'cash_bank'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<int, array{party_type?: mixed, percent?: mixed, applies_to?: mixed}> $shares */
            $shares = (array) $this->input('shares', []);

            $cardSum = 0.0;
            $cashSum = 0.0;
            foreach ($shares as $index => $share) {
                $percent = (float) ($share['percent'] ?? 0);
                $party = (string) ($share['party_type'] ?? '');
                $appliesTo = (string) ($share['applies_to'] ?? 'all');

                if ($party === 'bank') {
                    // An acquirer fee only exists on card money — a bank line
                    // scoped to cash/bank-POS is a contradiction, not a choice.
                    if ($appliesTo === 'cash_bank') {
                        $validator->errors()->add(
                            "shares.$index.applies_to",
                            'A bank line applies to card sales only — it cannot be scoped to cash/bank-POS.',
                        );
                    }
                    $cardSum += $percent;

                    continue;
                }

                if ($appliesTo === 'card') {
                    $cardSum += $percent;
                } elseif ($appliesTo === 'cash_bank') {
                    $cashSum += $percent;
                } else { // 'all' (or absent) bites both channels
                    $cardSum += $percent;
                    $cashSum += $percent;
                }
            }

            // Round to dodge float drift (e.g. 2.00 + 98.00 reading 100.0000001).
            if (round($cardSum, 2) > 100) {
                $validator->errors()->add(
                    'shares',
                    'The combined commission on CARD sales cannot exceed 100%. The merchant takes the remainder.',
                );
            }
            if (round($cashSum, 2) > 100) {
                $validator->errors()->add(
                    'shares',
                    'The combined commission on CASH / BANK-POS sales cannot exceed 100%. The merchant takes the remainder.',
                );
            }
        });
    }
}
