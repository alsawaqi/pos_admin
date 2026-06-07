<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BusinessActivityCategory;
use App\Models\BusinessActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessActivity>
 */
class BusinessActivityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('ACT-####')),
            'name_en' => fake()->words(2, true),
            'name_ar' => 'نشاط '.fake()->word(),
            'category' => fake()->randomElement(BusinessActivityCategory::cases()),
            'isic_code' => (string) fake()->numerify('####'),
            'description_en' => fake()->sentence(),
            'description_ar' => 'وصف النشاط التجاري',
            'is_active' => true,
            'display_order' => fake()->numberBetween(0, 100),
        ];
    }
}
