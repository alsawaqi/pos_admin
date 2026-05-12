<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DeviceActivationTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceActivationToken extends Model
{
    /** @use HasFactory<DeviceActivationTokenFactory> */
    use HasFactory;

    protected $table = 'pos_admin_device_activation_tokens';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'device_id',
        'token_hash',
        'created_by_user_id',
        'expires_at',
        'used_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
