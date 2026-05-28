<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\BusinessActivityCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for PATCH /admin/api/v1/business-activities/{id}.
 *
 * Every field uses `sometimes` so the admin can flip just is_active
 * without re-sending the whole record. The unique check on `code`
 * ignores the current row so saving without changing the code is
 * not flagged as a duplicate.
 */
class UpdateBusinessActivityRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $activityId = $this->route('activity')?->id;

        return [
            'code' => [
                'sometimes', 'required', 'string', 'max:32',
                Rule::unique('pos_business_activities', 'code')->ignore($activityId),
            ],
            'name_en' => ['sometimes', 'required', 'string', 'max:191'],
            'name_ar' => ['sometimes', 'required', 'string', 'max:191'],
            'category' => ['sometimes', 'required', Rule::enum(BusinessActivityCategory::class)],
            'isic_code' => ['sometimes', 'nullable', 'string', 'max:16'],
            'description_en' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'description_ar' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
