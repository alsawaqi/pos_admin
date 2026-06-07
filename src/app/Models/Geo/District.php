<?php

declare(strict_types=1);

namespace App\Models\Geo;

use Database\Factories\Geo\DistrictFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class District extends Model
{
    /** @use HasFactory<DistrictFactory> */
    use HasFactory;

    protected $table = 'districts';

    /** @var list<string> */
    protected $fillable = [
        'region_id',
        'name',
    ];

    /**
     * @return BelongsTo<Region, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * @return HasMany<City, $this>
     */
    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'region_id' => 'integer',
        ];
    }

    protected static function newFactory(): DistrictFactory
    {
        return DistrictFactory::new();
    }
}
