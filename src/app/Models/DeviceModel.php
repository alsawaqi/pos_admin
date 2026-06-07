<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DeviceModelFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A specific hardware product within a {@see DeviceMake}
 * (e.g. Sunmi → P2 Mini). Lives in pos_device_models.
 *
 * Note the class name shadows Laravel's own `Illuminate\Database\
 * Eloquent\Model` if you `use` both in the same file — anywhere
 * inside this app, when we mean the Eloquent base class we import
 * it via its FQCN inline rather than aliasing here.
 */
class DeviceModel extends Model
{
    /** @use HasFactory<DeviceModelFactory> */
    use HasFactory;

    protected $table = 'pos_device_models';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'make_id',
        'name',
        'code',
        'display_order',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    /**
     * Parent manufacturer. Required — the FK is NOT NULL.
     *
     * @return BelongsTo<DeviceMake, $this>
     */
    public function make(): BelongsTo
    {
        return $this->belongsTo(DeviceMake::class, 'make_id');
    }

    /**
     * Devices registered as this model. Used by the in-use guard on
     * DeleteDeviceModelAction.
     *
     * @return HasMany<Device, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'model_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
