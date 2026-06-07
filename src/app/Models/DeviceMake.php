<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DeviceMakeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Manufacturer of a physical POS device (Sunmi, PAX, NEXGO, …).
 *
 * Lives in the platform-wide `pos_device_makes` catalogue table.
 * Used by the Register Device form's Make → Model cascading
 * dropdowns. Admin manages the list from
 * Settings → Device catalogue.
 */
class DeviceMake extends Model
{
    /** @use HasFactory<DeviceMakeFactory> */
    use HasFactory;

    protected $table = 'pos_device_makes';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
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
     * Hardware models this manufacturer offers. The cascade FK on
     * pos_device_models means deleting the make also wipes its
     * models — but the Action layer refuses to delete a make in use
     * by any device, so this only triggers for orphan rows.
     *
     * @return HasMany<DeviceModel, $this>
     */
    public function models(): HasMany
    {
        return $this->hasMany(DeviceModel::class, 'make_id')
            ->orderBy('display_order')
            ->orderBy('name');
    }

    /**
     * Devices currently registered against this make. Used by the
     * "in use" guard on DeleteDeviceMakeAction to block destruction
     * of a make that still has devices pointing at it.
     *
     * @return HasMany<Device, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'make_id');
    }

    /**
     * Scope helper for "only the entries visible in the Register
     * Device dropdown". The admin catalogue page can flip an entry
     * to is_active=false to retire it without breaking existing
     * device records.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
