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

    /**
     * LAUNCH-P1 P1-1/P1-7: a file that PHP refused (bigger than the
     * server's upload limit, or an interrupted upload) arrives as a
     * failed upload; say so in words the admin can act on.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.uploaded' => 'The file did not reach the server. It may be larger than 10 MB or the upload was interrupted; please try again with a smaller file.',
            'file.max' => 'The file is larger than 10 MB. Please upload a smaller scan or photo.',
            'file.mimes' => 'Only PDF, JPG and PNG files can be uploaded.',
        ];
    }
}
