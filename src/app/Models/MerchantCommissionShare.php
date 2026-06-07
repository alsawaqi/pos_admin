<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommissionPartyType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One non-merchant split line of a {@see MerchantCommissionProfile}
 * (pos_commission_shares): a platform / bank / other party that takes a
 * percent of every sale.
 */
class MerchantCommissionShare extends Model
{
    protected $table = 'pos_commission_shares';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'commission_profile_id',
        'party_type',
        'label',
        'percent',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'party_type' => CommissionPartyType::class,
            'percent' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<MerchantCommissionProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(MerchantCommissionProfile::class, 'commission_profile_id');
    }
}
