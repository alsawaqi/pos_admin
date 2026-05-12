<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BranchStatus;
use App\Models\Concerns\HasCompanyScope;
use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\District;
use App\Models\Geo\Region;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasCompanyScope, HasFactory, SoftDeletes;

    protected $table = 'pos_admin_branches';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'company_id',
        'name',
        'code',
        'manager_name',
        'phone',
        'email',
        'address',
        'country_id',
        'region_id',
        'district_id',
        'city_id',
        'latitude',
        'longitude',
        'status',
        'settings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'country_id' => 'integer',
            'region_id' => 'integer',
            'district_id' => 'integer',
            'city_id' => 'integer',
            'status' => BranchStatus::class,
            'settings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<Device, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * @return BelongsTo<Region, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * @return BelongsTo<District, $this>
     */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'pos_admin_branch_user')
            ->withPivot(['company_id', 'assigned_by_user_id', 'is_primary'])
            ->withTimestamps();
    }
}
