<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /admin/api/v1/marketing/content/{contentAsset}/reject — an optional note
 * explaining the rejection, shown to the advertiser on their Approvals page.
 */
class RejectContentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
