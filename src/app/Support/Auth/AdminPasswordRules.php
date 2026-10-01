<?php

declare(strict_types=1);

namespace App\Support\Auth;

use Illuminate\Validation\Rules\Password;

/**
 * Password rules for platform admins (LAUNCH-P1 P1-8): at least 12
 * characters with letters and numbers, confirmed. Used by the
 * set-password / reset page and the change-password form.
 */
final class AdminPasswordRules
{
    /**
     * @return list<mixed>
     */
    public static function rules(): array
    {
        return ['required', 'string', 'confirmed', 'max:255', Password::min(12)->letters()->numbers()];
    }
}
