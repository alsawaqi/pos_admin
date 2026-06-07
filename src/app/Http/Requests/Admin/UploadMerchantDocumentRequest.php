<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadMerchantDocumentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::enum(DocumentType::class)],
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
            'issued_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:issued_at'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
