<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MarketingSliderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A marketing slider — an ordered loop of approved advertiser content the admin
 * curates and targets at devices/locations. pos_admin-owned; the content it
 * references lives in the marketing-api-owned content_assets.
 */
class MarketingSlider extends Model
{
    /** @use HasFactory<MarketingSliderFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'pos_marketing_sliders';

    protected $fillable = [
        'uuid',
        'name',
        'loop_interval_seconds',
        'status',
        'starts_at',
        'ends_at',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'loop_interval_seconds' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return HasMany<MarketingSliderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(MarketingSliderItem::class, 'slider_id')->orderBy('sort_order');
    }

    /** @return HasMany<MarketingSliderTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(MarketingSliderTarget::class, 'slider_id');
    }
}
