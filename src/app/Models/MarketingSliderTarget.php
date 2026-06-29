<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MarketingSliderTargetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a slider plays. A branch and/or a specific device. A slider with no
 * target rows plays everywhere (all branches).
 */
class MarketingSliderTarget extends Model
{
    /** @use HasFactory<MarketingSliderTargetFactory> */
    use HasFactory;

    protected $table = 'pos_marketing_slider_targets';

    protected $fillable = [
        'slider_id',
        'branch_id',
        'device_id',
    ];

    /** @return BelongsTo<MarketingSlider, $this> */
    public function slider(): BelongsTo
    {
        return $this->belongsTo(MarketingSlider::class, 'slider_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
