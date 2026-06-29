<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Enums\UserType;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'pos_companies';

    /**
     * @var list<string>
     */
    // The owner_* columns were dropped by the
    // 2026_05_24_030000_create_pos_company_owners_table migration. Owner
    // identity now lives on a separate {@see CompanyOwner} child table.
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
        'default_currency',
        'default_locale',
        'onboarded_by_user_id',
        'status',
        // True for an advertising-only company onboarded through the marketing
        // platform — kept out of the Merchants list / device fan-out.
        'is_advertiser_only',
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
            'is_advertiser_only' => 'boolean',
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
     * Multiple owner identities per company (blueprint §4.2.2 +
     * extended to support partnerships). Order is primary-first then
     * insertion order so the Vue Show page renders the canonical
     * owner at the top automatically.
     *
     * @return HasMany<CompanyOwner, $this>
     */
    public function owners(): HasMany
    {
        return $this->hasMany(CompanyOwner::class)
            ->orderByDesc('is_primary')
            ->orderBy('id');
    }

    /**
     * Merchant portal users (blueprint §4.5). Filtered by
     * user_type=merchant so we don't accidentally hand back the
     * admin staff (who share the same `pos_users` table).
     *
     * Used by Laravel's implicit scoped route binding on
     * /merchants/{merchant:uuid}/portal-users/{portalUser} — the
     * router uses this relation to look the portal user up under
     * the parent merchant so a cross-tenant id naturally 404s.
     *
     * @return HasMany<User, $this>
     */
    public function portalUsers(): HasMany
    {
        return $this->hasMany(User::class)
            ->where('user_type', UserType::Merchant)
            ->orderByDesc('created_at');
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
        return $this->belongsToMany(BusinessActivity::class, 'pos_company_activities')
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

    /**
     * The platform's per-merchant commission split (pos_commission_profiles).
     * POS-owned, distinct from the charity {@see CommissionProfile} the
     * device carries for the round-up donation snapshot.
     *
     * @return HasOne<MerchantCommissionProfile, $this>
     */
    public function commissionProfile(): HasOne
    {
        return $this->hasOne(MerchantCommissionProfile::class);
    }
}
