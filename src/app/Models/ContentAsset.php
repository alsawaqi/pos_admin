<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ContentAssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Advertiser-uploaded content (image / video). OWNED by the marketing-api app
 * (shared charity_db); pos_admin reads it for review and writes ONLY the review
 * fields — status (approved / rejected), review_note, reviewed_at. The content
 * itself (title, file, etc.) belongs to the advertiser.
 */
class ContentAsset extends Model
{
    /** @use HasFactory<ContentAssetFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'content_assets';

    // Review-only: pos_admin never edits an advertiser's content fields.
    protected $fillable = [
        'status',
        'review_note',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_seconds' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function advertiser(): BelongsTo
    {
        return $this->belongsTo(Advertiser::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Absolute display URL for the file. The file lives on the marketing-api
     * app's `public` disk (a DIFFERENT host), and marketing-api leaves the `url`
     * column null and computes the URL at read time — so pos_admin can't use its
     * own storage URL. We rebuild it from the stored `path` and the configured
     * marketing public base (config services.marketing.public_url). Falls back to
     * the `url` column if it ever gets populated with an absolute URL.
     */
    public function getPublicUrlAttribute(): ?string
    {
        return $this->buildMarketingUrl($this->getRawOriginal('url'), $this->path);
    }

    public function getThumbnailPublicUrlAttribute(): ?string
    {
        return $this->buildMarketingUrl($this->getRawOriginal('thumbnail_url'), $this->thumbnail_path);
    }

    private function buildMarketingUrl(?string $absolute, ?string $path): ?string
    {
        if (! empty($absolute)) {
            return $absolute;
        }
        if (empty($path)) {
            return null;
        }

        $base = rtrim((string) config('services.marketing.public_url'), '/');

        return $base . '/storage/' . ltrim($path, '/');
    }
}
