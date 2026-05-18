<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise the request before validation so both XHR (JSON) and
     * native HTML form submissions reach the same shape. Checkbox inputs
     * arrive as "on" when ticked or are absent when not — Laravel's
     * boolean rule does not accept "on", hence the explicit coercion.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'remember' => $this->boolean('remember'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{email: string, password: string}
     */
    public function credentials(): array
    {
        return [
            'email' => (string) $this->string('email')->lower(),
            'password' => (string) $this->string('password'),
        ];
    }

    public function remember(): bool
    {
        return $this->boolean('remember');
    }
}
