<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyOwner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test factory for {@see CompanyOwner}. Default state creates a
 * non-primary owner; chain `->primary()` for the canonical owner.
 *
 * @extends Factory<CompanyOwner>
 */
class CompanyOwnerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // company_id is normally injected by ->for(Company $c)
            // in test bodies; fall back to creating one so the
            // factory works standalone.
            'company_id' => Company::factory(),
            'full_name_en' => fake()->name(),
            'full_name_ar' => fake()->firstName().' '.fake()->lastName(),
            'civil_id' => (string) fake()->numerify('########'),
            'nationality' => 'OM',
            'phone' => '+968'.fake()->numerify('########'),
            'email' => fake()->safeEmail(),
            'is_primary' => false,
            'ownership_percentage' => null,
        ];
    }

    /**
     * Marks the owner row as the company's primary contact of
     * record. Used by CompanyFactory::withOwner().
     */
    public function primary(): self
    {
        return $this->state(fn (): array => [
            'is_primary' => true,
        ]);
    }
}
