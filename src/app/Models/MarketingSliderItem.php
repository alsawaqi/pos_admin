<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MarketingSliderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ordered item in a slider — a reference to an advertiser content asset
 * with a per-item duration. advertiser_id is snapshotted for grouping +
 * competitor checks.
 */
class MarketingSliderItem extends Model
{
    /** @use HasFactory<MarketingSliderItemFactory> */
    use HasFactory;

    protected $table = 'pos_marketing_slider_items';

    protected $fillable = [
        'slider_id',
        'content_asset_id',
        'advertiser_id',
        'sort_order',
        'duration_seconds',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'duration_seconds' => 'integer',
        ];
    }

    /** @return BelongsTo<MarketingSlider, $this> */
    public function slider(): BelongsTo
    {
        return $this->belongsTo(MarketingSlider::class, 'slider_id');
    }

    /** @return BelongsTo<ContentAsset, $this> */
    public function contentAsset(): BelongsTo
    {
        return $this->belongsTo(ContentAsset::class, 'content_asset_id');
    }

    /** @return BelongsTo<Advertiser, $this> */
    public function advertiser(): BelongsTo
    {
        return $this->belongsTo(Advertiser::class, 'advertiser_id');
    }
}
