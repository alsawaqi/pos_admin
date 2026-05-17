<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CompanyStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class CompanyStatusHistory extends Model
{
    public const CREATED_AT = 'changed_at';
    public const UPDATED_AT = null;

    protected $table = 'pos_admin_company_status_history';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'from_status',
        'to_status',
        'changed_by_user_id',
        'reason',
        'metadata',
        'changed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => CompanyStatus::class,
            'to_status' => CompanyStatus::class,
            'metadata' => 'array',
            'changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new RuntimeException('Company status history entries are immutable.');
        });

        static::deleting(static function (): never {
            throw new RuntimeException('Company status history entries cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
