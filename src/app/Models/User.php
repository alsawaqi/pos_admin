<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserStatus;
use App\Enums\UserType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'company_id',
    'name',
    'email',
    'phone',
    'password',
    'user_type',
    'status',
    'last_login_at',
    'timezone',
    'locale',
    'metadata',
    // Portal-user fields added by the
    // 2026_05_24_040000 migration. Merchant portal users live in
    // pos_users alongside admin staff, differentiated by user_type.
    'setup_token_hash',
    'setup_token_expires_at',
    'branch_scope_json',
    'invited_at',
    'invited_by_admin_id',
])]
#[Hidden(['password', 'remember_token', 'setup_token_hash', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $table = 'pos_users';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'user_type' => UserType::class,
            'status' => UserStatus::class,
            'last_login_at' => 'datetime',
            'metadata' => 'array',
            // Setup-link lifecycle + branch scoping for merchant
            // portal users. Token hash is intentionally NOT cast to
            // 'hashed' — the bcrypt cast is for password verification
            // semantics, but our setup token uses SHA-256 hashing
            // explicitly (see InvitePortalUserAction).
            'setup_token_expires_at' => 'datetime',
            'branch_scope_json' => 'array',
            'invited_at' => 'datetime',
            // Sprint 3 — application-layer encryption on phone
            // (PII, blueprint §9.13.2). Column was widened to TEXT
            // in 2026_05_26_030000_widen_pii_columns_for_encryption
            // so the ciphertext (~3× plaintext) always fits.
            // Note: email is intentionally NOT encrypted because it
            // is the login key — we WHERE on it during authentication
            // and the non-deterministic ciphertext would break that.
            'phone' => 'encrypted',
            // Phase D8 — TOTP 2FA. Secret + recovery-code hashes are
            // encrypted at rest (shared APP_KEY with pos_merchant, so
            // the ciphertext interoperates across both portals;
            // NOTE a key rotation bricks every enrolled secret).
            // The recovery codes value is a JSON array of SHA-256
            // hashes — the plaintext codes are never stored.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * True once the user finished TOTP enrolment (confirmed a valid
     * code). A stored-but-unconfirmed secret never gates login.
     */
    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'pos_branch_user')
            ->withPivot(['company_id', 'assigned_by_user_id', 'is_primary'])
            ->withTimestamps();
    }

    /**
     * Restrict any query to platform admin rows.
     *
     * Companion to pos_merchant's `scopeMerchant()`. The shared
     * pos_users table holds both populations differentiated only by
     * user_type, so anywhere we look up a "user" by email — login
     * candidate lookups in particular — we MUST narrow to this scope
     * first. Otherwise a merchant credential pair would satisfy
     * Auth::attempt() against the same hashed password column and
     * land an unrelated user inside /admin.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopePlatformAdmin(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('user_type', UserType::PlatformAdmin->value);
    }

    /**
     * True iff this row's user_type is platform_admin. Every gate that
     * cares about "is this really a pos_admin user, not a merchant who
     * slipped a stale session past us" should call this rather than
     * trusting the presence of an auth cookie alone.
     */
    public function isPlatformAdmin(): bool
    {
        return $this->user_type === UserType::PlatformAdmin;
    }
}
