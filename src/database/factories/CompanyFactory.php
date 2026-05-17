<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CompanyStatus;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $companyName = fake()->company();

        return [
            'uuid' => (string) Str::uuid(),
            'name' => $companyName,
            'name_ar' => 'شركة '.fake()->lastName(),
            'legal_name' => $companyName.' LLC',
            'legal_name_ar' => 'شركة '.fake()->lastName().' ذ.م.م',
            'cr_number' => (string) fake()->unique()->numerify('1#######'),
            'cr_issue_date' => fake()->dateTimeBetween('-5 years', '-1 month'),
            'cr_expiry_date' => fake()->dateTimeBetween('+6 months', '+5 years'),
            'establishment_date' => fake()->dateTimeBetween('-10 years', '-1 year'),
            'vat_number' => (string) fake()->numerify('OM##########'),
            'vat_registered_at' => fake()->dateTimeBetween('-3 years', '-1 month'),
            'chamber_of_commerce_number' => (string) fake()->numerify('CH#######'),
            'municipality_license_number' => (string) fake()->numerify('MUN######'),
            'contact_name' => fake()->name(),
            'contact_phone' => '+968'.fake()->numerify('########'),
            'contact_email' => fake()->companyEmail(),
            'owner_full_name_en' => fake()->name(),
            'owner_full_name_ar' => fake()->firstName().' '.fake()->lastName(),
            'owner_civil_id' => (string) fake()->numerify('########'),
            'owner_nationality' => 'OM',
            'owner_phone' => '+968'.fake()->numerify('########'),
            'owner_email' => fake()->safeEmail(),
            'default_currency' => 'OMR',
            'default_locale' => 'en',
            'status' => CompanyStatus::Onboarding,
            'settings' => [],
        ];
    }

    public function active(): self
    {
        return $this->state(fn (): array => [
            'status' => CompanyStatus::Active,
            'activated_at' => now(),
        ]);
    }

    public function suspended(): self
    {
        return $this->state(fn (): array => [
            'status' => CompanyStatus::Suspended,
            'activated_at' => now()->subMonth(),
            'suspended_at' => now(),
            'suspension_reason' => 'Compliance review',
        ]);
    }
}
