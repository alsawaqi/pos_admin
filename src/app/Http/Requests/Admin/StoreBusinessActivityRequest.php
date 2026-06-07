<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\BusinessActivityCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for POST /admin/api/v1/business-activities — used by
 * the Settings → Business Activities admin page.
 *
 * Code is the human-readable short id (e.g. "F&B-CAFE") that the
 * onboarding officer can quote when emailing merchants. Globally
 * unique; surfaces in the URL of the activity detail page if we
 * ever add one.
 */
class StoreBusinessActivityRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32', Rule::unique('pos_business_activities', 'code')],
            'name_en' => ['required', 'string', 'max:191'],
            'name_ar' => ['required', 'string', 'max:191'],
            'category' => ['required', Rule::enum(BusinessActivityCategory::class)],
            // ISIC (International Standard Industrial Classification)
            // code — optional reference for cross-mapping.
            'isic_code' => ['nullable', 'string', 'max:16'],
            'description_en' => ['nullable', 'string', 'max:2000'],
            'description_ar' => ['nullable', 'string', 'max:2000'],
            // Default true so a newly-added activity shows up
            // immediately in the merchant wizard.
            'is_active' => ['nullable', 'boolean'],
            // Lower values render first in the wizard. Defaults to 0
            // (which puts the activity wherever fits alphabetically
            // within its category).
            'display_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }
}
