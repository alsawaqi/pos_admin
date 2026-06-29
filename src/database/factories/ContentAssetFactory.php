<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Advertiser;
use App\Models\ContentAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentAsset>
 */
class ContentAssetFactory extends Factory
{
    protected $model = ContentAsset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->words(3, true);

        return [
            'advertiser_id' => Advertiser::factory(),
            'title' => ucfirst($title),
            'type' => 'image',
            'status' => 'pending',
            'sort_order' => 0,
            'disk' => 'public',
            'path' => 'content-assets/'.fake()->uuid().'.jpg',
            'url' => 'https://cdn.example.test/'.fake()->uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'size_bytes' => 123456,
            'width' => 1080,
            'height' => 1080,
            'submitted_at' => now(),
        ];
    }

    public function video(): static
    {
        return $this->state(fn (): array => [
            'type' => 'video',
            'path' => 'content-assets/'.fake()->uuid().'.mp4',
            'url' => 'https://cdn.example.test/'.fake()->uuid().'.mp4',
            'mime_type' => 'video/mp4',
            'extension' => 'mp4',
            'duration_seconds' => 30,
            'thumbnail_url' => 'https://cdn.example.test/'.fake()->uuid().'.jpg',
        ]);
    }

    public function status(string $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
