<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BankReconciliationCommitRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payment_ids' => ['required', 'array', 'min:1'],
            'payment_ids.*' => ['integer', 'exists:pos_payments,id'],
            // A2 — optional { payment_id: actual_bank_fee } captured from the
            // statement, persisted so settlement can pre-fill the fee.
            'fees' => ['nullable', 'array'],
            'fees.*' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
