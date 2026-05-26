<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Loose validation by design — the per-key type coercion lives
 * in {@see \App\Actions\Admin\UpdateSettingsAction::coerce()}.
 * Doing it twice (once via Laravel rules, once in the action)
 * would mean every new setting type needs touching three files
 * instead of one.
 *
 * The only invariant we enforce here is that the payload is a
 * `settings` map (key → value). Unknown keys are caught by the
 * action with a RuntimeException → 422 in the controller.
 */
class UpdateSettingsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array'],
            // The values themselves can be any of string / int /
            // bool / array / null — accepting `present` keeps the
            // rule open. The action does the type coercion.
            'settings.*' => ['present'],
        ];
    }
}
