<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AdvertiserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A marketing-platform advertiser. The row is OWNED by the marketing-api app
 * (shared charity_db); pos_admin reads + writes it for admin-driven onboarding
 * — create the account, optionally link it to a merchant {@see Company}, suspend,
 * reset password. Authentication happens on the marketing portal, not here.
 */
class Advertiser extends Model
{
    /** @use HasFactory<AdvertiserFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'advertisers';

    protected $fillable = [
        'name',
        'brand_name',
        'email',
        'password',
        'phone',
        'status',
        'company_id',
        'is_merchant',
        'category',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_merchant' => 'boolean',
        ];
    }

    /** The linked POS merchant, when this advertiser is also a merchant. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }
}
