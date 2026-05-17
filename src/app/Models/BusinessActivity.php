<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BusinessActivityCategory;
use Database\Factories\BusinessActivityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BusinessActivity extends Model
{
    /** @use HasFactory<BusinessActivityFactory> */
    use HasFactory;

    protected $table = 'pos_admin_business_activities';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name_en',
        'name_ar',
        'category',
        'isic_code',
        'description_en',
        'description_ar',
        'is_active',
        'display_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => BusinessActivityCategory::class,
            'is_active' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'pos_admin_company_activities')
            ->withPivot('is_primary')
            ->withTimestamps();
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
