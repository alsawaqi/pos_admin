<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Support\StatusTransitions\CompanyStatusTransitions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionMerchantStatusRequest extends FormRequest
{
    /**
     * Reopening a closed (Inactive) merchant is for a Super Admin only
     * (owner decision 2026-10-01). Refused here, before validation, so a
     * non-Super-Admin gets a plain 403 whatever it sent. The controller
     * still checks transitionStatus, and the action enforces the rule
     * again for any other caller.
     */
    public function authorize(): bool
    {
        $merchant = $this->route('merchant');

        if ($merchant instanceof Company && CompanyStatusTransitions::isReopen($merchant)) {
            return (bool) $this->user()?->can('reopen', $merchant);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'target_status' => ['required', Rule::enum(CompanyStatus::class)],
            // Required to suspend, and to reopen a closed merchant.
            'reason' => [
                'nullable', 'string', 'max:1000',
                Rule::requiredIf(fn (): bool => $this->input('target_status') === CompanyStatus::Suspended->value
                    || $this->isReopen()),
            ],
        ];
    }

    private function isReopen(): bool
    {
        $merchant = $this->route('merchant');

        return $merchant instanceof Company && CompanyStatusTransitions::isReopen($merchant);
    }
}
