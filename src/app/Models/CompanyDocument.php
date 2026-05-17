<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentType;
use App\Enums\DocumentVerificationStatus;
use Database\Factories\CompanyDocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class CompanyDocument extends Model
{
    /** @use HasFactory<CompanyDocumentFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'pos_admin_company_documents';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'company_id',
        'document_type',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'sha256',
        'uploaded_by_user_id',
        'verified_by_user_id',
        'verification_status',
        'verified_at',
        'rejection_reason',
        'issued_at',
        'expires_at',
        'notes',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'verification_status' => DocumentVerificationStatus::class,
            'size_bytes' => 'integer',
            'verified_at' => 'datetime',
            'issued_at' => 'date',
            'expires_at' => 'date',
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
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function isExpired(?Carbon $now = null): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->lt($now ?? Carbon::now());
    }

    public function daysUntilExpiry(?Carbon $now = null): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        return (int) ($now ?? Carbon::now())->startOfDay()->diffInDays($this->expires_at->startOfDay(), false);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('expires_at')
            ->whereBetween('expires_at', [Carbon::now()->startOfDay(), Carbon::now()->addDays($days)->endOfDay()])
            ->where('verification_status', '!=', DocumentVerificationStatus::Expired->value);
    }
}
