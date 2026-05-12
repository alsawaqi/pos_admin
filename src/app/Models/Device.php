<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeviceStatus;
use App\Models\Concerns\HasCompanyScope;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasCompanyScope, HasFactory, SoftDeletes;

    protected $table = 'pos_admin_devices';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'serial_number',
        'name',
        'device_type',
        'company_id',
        'branch_id',
        'registered_by_user_id',
        'assigned_by_user_id',
        'status',
        'assigned_at',
        'last_seen_at',
        'last_ip',
        'app_version',
        'firmware_version',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeviceStatus::class,
            'assigned_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'metadata' => 'array',
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
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    /**
     * @return HasMany<DeviceActivationToken, $this>
     */
    public function activationTokens(): HasMany
    {
        return $this->hasMany(DeviceActivationToken::class);
    }
}
