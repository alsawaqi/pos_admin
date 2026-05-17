<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CompanyStatus;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'pos_admin_companies';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'name',
        'name_ar',
        'legal_name',
        'legal_name_ar',
        'cr_number',
        'cr_issue_date',
        'cr_expiry_date',
        'establishment_date',
        'tax_number',
        'vat_number',
        'vat_registered_at',
        'chamber_of_commerce_number',
        'municipality_license_number',
        'contact_name',
        'contact_phone',
        'contact_email',
        'owner_full_name_en',
        'owner_full_name_ar',
        'owner_civil_id',
        'owner_nationality',
        'owner_phone',
        'owner_email',
        'default_currency',
        'default_locale',
        'onboarded_by_user_id',
        'status',
        'activated_at',
        'suspended_at',
        'suspension_reason',
        'settings',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'settings' => 'array',
            'cr_issue_date' => 'date',
            'cr_expiry_date' => 'date',
            'establishment_date' => 'date',
            'vat_registered_at' => 'date',
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return HasMany<Branch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /**
     * @return HasMany<Device, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<AuditLog, $this>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * @return HasMany<CompanyDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(CompanyDocument::class);
    }

    /**
     * @return HasMany<CompanyStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(CompanyStatusHistory::class)->orderByDesc('changed_at');
    }

    /**
     * @return BelongsToMany<BusinessActivity, $this>
     */
    public function activities(): BelongsToMany
    {
        return $this->belongsToMany(BusinessActivity::class, 'pos_admin_company_activities')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function onboardedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'onboarded_by_user_id');
    }
}
