<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Projection of a {@see Setting} row for the Settings page.
 *
 * Unwraps the `{"v": ...}` storage envelope so the frontend sees
 * the raw scalar (matching how it'll send it back on update). The
 * Setting::get() helper does the same unwrap; this resource is
 * the parallel for read-via-controller.
 *
 * @mixin Setting
 */
class SettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'value' => $this->unwrappedValue(),
            'type' => $this->type,
            'group_key' => $this->group_key,
            'label_en' => $this->label_en,
            'label_ar' => $this->label_ar,
            'help_en' => $this->help_en,
            'help_ar' => $this->help_ar,
            'options' => $this->options,
            'display_order' => $this->display_order,
        ];
    }

    private function unwrappedValue(): mixed
    {
        $raw = $this->value;
        if (is_array($raw) && array_key_exists('v', $raw) && count($raw) === 1) {
            return $raw['v'];
        }
        return $raw;
    }
}
