<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /admin/api/v1/marketing/sliders — create a slider with its ordered items
 * + targets. Items reference approved advertiser content; targets scope it to
 * branches/devices (none = all branches).
 */
class StoreSliderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'loop_interval_seconds' => ['nullable', 'integer', 'min:2', 'max:120'],
            'status' => ['nullable', Rule::in(['draft', 'active', 'paused'])],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],

            'items' => ['present', 'array'],
            'items.*.content_asset_id' => ['required', 'integer', Rule::exists('content_assets', 'id')],
            'items.*.duration_seconds' => ['nullable', 'integer', 'min:2', 'max:120'],

            'targets' => ['nullable', 'array'],
            'targets.*.branch_id' => ['nullable', 'integer', Rule::exists('pos_branches', 'id')],
            'targets.*.device_id' => ['nullable', 'integer', Rule::exists('pos_devices', 'id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // A live slider must have something to show.
            if ($this->input('status') === 'active' && count((array) $this->input('items', [])) === 0) {
                $v->errors()->add('items', 'A slider needs at least one item before it can go live.');
            }
        });
    }
}
