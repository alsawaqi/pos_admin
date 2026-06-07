<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\CompanyStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionMerchantStatusRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'target_status' => ['required', Rule::enum(CompanyStatus::class)],
            'reason' => ['nullable', 'string', 'max:1000', 'required_if:target_status,suspended'],
        ];
    }
}
