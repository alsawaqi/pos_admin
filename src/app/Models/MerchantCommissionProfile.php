<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * POS-owned, per-merchant commission profile (pos_commission_profiles).
 *
 * NOT the charity-owned {@see CommissionProfile} (table commission_profiles,
 * read-only, no rates). This one is the platform's revenue split for a
 * merchant's sales: the configurable share lines live on {@see
 * MerchantCommissionShare}; the merchant keeps the residual stored here as
 * merchant_percent.
 */
class MerchantCommissionProfile extends Model
{
    protected $table = 'pos_commission_profiles';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'company_id',
        'is_active',
        'merchant_percent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'merchant_percent' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<MerchantCommissionShare, $this>
     */
    public function shares(): HasMany
    {
        return $this->hasMany(MerchantCommissionShare::class, 'commission_profile_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
