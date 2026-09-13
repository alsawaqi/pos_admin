<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SoftPosProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankSoftPosProfile extends Model
{
    protected $table = 'pos_bank_softpos_profiles';

    protected $fillable = [
        'bank_id', 'softpos_provider', 'softpos_package', 'currency_code',
        'refund_needs_transaction_id', 'void_needs_session_id', 'min_app_version',
        'is_active', 'notes', 'created_by_user_id', 'updated_by_user_id', 'provider_changed_at',
    ];

    protected function casts(): array
    {
        return [
            'softpos_provider' => SoftPosProvider::class,
            'refund_needs_transaction_id' => 'boolean',
            'void_needs_session_id' => 'boolean',
            'is_active' => 'boolean',
            'provider_changed_at' => 'datetime',
        ];
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function deviceResource(Device $device): array
    {
        return [
            'provider' => $this->softpos_provider->value,
            'label' => $this->softpos_provider->label(),
            'package' => $this->softpos_package,
            'currency' => $this->currency_code,
            'blocked_reason' => $device->card_tenders_blocked_reason,
            'blocked_at' => $device->card_tenders_blocked_at,
        ];
    }
}
