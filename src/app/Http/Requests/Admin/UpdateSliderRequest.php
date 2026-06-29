<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /admin/api/v1/marketing/sliders/{slider}. Any subset of fields; when
 * `items` or `targets` are present they fully replace the existing set.
 */
class UpdateSliderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'loop_interval_seconds' => ['sometimes', 'integer', 'min:2', 'max:120'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'paused'])],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],

            'items' => ['sometimes', 'array'],
            'items.*.content_asset_id' => ['required', 'integer', Rule::exists('content_assets', 'id')],
            'items.*.duration_seconds' => ['nullable', 'integer', 'min:2', 'max:120'],

            'targets' => ['sometimes', 'array'],
            'targets.*.branch_id' => ['nullable', 'integer', Rule::exists('pos_branches', 'id')],
            'targets.*.device_id' => ['nullable', 'integer', Rule::exists('pos_devices', 'id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // Going live needs at least one item. Use the incoming items when
            // provided; otherwise the existing slider keeps its set.
            if ($this->input('status') === 'active' && $this->has('items') && count((array) $this->input('items', [])) === 0) {
                $v->errors()->add('items', 'A slider needs at least one item before it can go live.');
            }
        });
    }
}
