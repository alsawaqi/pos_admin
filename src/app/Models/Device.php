<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeviceStatus;
use App\Enums\DeviceType;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;

/**
 * A physical Android device owned by MITHQAL and provisioned to a
 * merchant's branch. The blueprint splits them into three classes
 * (see {@see DeviceType}) but they all share this single row.
 *
 * Devices have a two-step provisioning lifecycle (blueprint §6.1 +
 * §9.10):
 *   1. Admin REGISTERS the device by entering its scalefusion kiosk
 *      id; status becomes `registered`.
 *   2. Admin ASSIGNS the device to a (company, branch); status becomes
 *      `assigned` and a row is opened in
 *      {@see DeviceAssignmentHistory}.
 *   3. On first POS boot the device exchanges its kiosk id for a
 *      long-lived `device_token` (stored hashed here, never plaintext)
 *      and starts heartbeating GPS + battery — `last_lat / lng /
 *      battery` get populated and `status` is promoted to `active`.
 *
 * The {@see BelongsToCompany} trait auto-scopes queries to the active
 * tenant context, which is the cornerstone of the multi-tenancy
 * guarantee in blueprint §9.11.
 */
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use BelongsToCompany, HasApiTokens, HasFactory, SoftDeletes;

    // Lane A1 — Sanctum personal-access-tokens are polymorphic;
    // adding HasApiTokens here lets the Device pose as a
    // tokenable. The Android POS exchanges its activation
    // code for a Device PAT, then carries `Authorization:
    // Bearer <token>` on every sync / order call. Token
    // verification + tokenable resolution happen automatically
    // inside Sanctum's middleware — see pos_merchant's
    // ResolveDeviceTenantContext for the tenant-pinning step.

    protected $table = 'pos_devices';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'serial_number',
        'kiosk_id',
        // Bank-issued terminal identifier (added by the
        // 2026_05_25_020000 migration). Unique platform-wide so
        // the soft-POS reconciler can route by it.
        'terminal_id',
        // Per-device Mosambee Soft-POS login PIN, issued by the bank
        // alongside the terminal_id at assign time (added by the
        // 2026_07_05_000000 migration). Nullable — devices without
        // one fall back to the vendor default PIN. PLAIN string, no
        // encrypted cast: the table is shared with pos_api which
        // runs a different APP_KEY in production.
        'terminal_pin',
        // FK into the shared charity_db.commission_profiles table.
        // Used by the donation-write path to compute the round-up
        // split.
        'commission_profile_id',
        // FK into the shared charity_db.banks table. Disambiguates
        // which acquiring bank owns the terminal_id — a merchant
        // can have multiple banks across their estate so the
        // reconciler needs to know which bank's API to call.
        'bank_id',
        // FK into the shared charity_db.organizations table — the beneficiary
        // org the device's card round-up donations go to (picked at register).
        'organization_id',
        'name',
        'device_type',
        // Catalogue FKs (added by the
        // 2026_05_25_010100 migration). The free-text `model` string
        // column they replaced was dropped in that same migration.
        'make_id',
        'model_id',
        'label',
        'device_token',
        'company_id',
        'branch_id',
        'registered_by_user_id',
        'assigned_by_user_id',
        'status',
        'assigned_at',
        'last_seen_at',
        'last_ip',
        'last_lat',
        'last_lng',
        'last_battery',
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
            // Enum casts give us type-safe access elsewhere
            // (`$device->status === DeviceStatus::Active`).
            'status' => DeviceStatus::class,
            'device_type' => DeviceType::class,

            // Timestamps + geo numerics + the JSON blob for any
            // scalefusion-side metadata we don't have a dedicated
            // column for yet.
            'assigned_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_lat' => 'decimal:7',
            'last_lng' => 'decimal:7',
            'last_battery' => 'integer',
            'metadata' => 'array',
        ];
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
     * One-time activation tokens. The POS app exchanges one of these
     * for its long-lived `device_token` at first boot (blueprint §6.1).
     * Once redeemed, `DeviceActivationToken::used_at` is stamped and
     * the token never validates again.
     *
     * @return HasMany<DeviceActivationToken, $this>
     */
    public function activationTokens(): HasMany
    {
        return $this->hasMany(DeviceActivationToken::class);
    }

    /**
     * Manufacturer of this physical device (Sunmi, PAX, NEXGO …).
     * Optional because legacy / scalefusion-unknown devices may not
     * have a make identified yet — DeviceResource handles the null.
     *
     * @return BelongsTo<DeviceMake, $this>
     */
    public function make(): BelongsTo
    {
        return $this->belongsTo(DeviceMake::class, 'make_id');
    }

    /**
     * Specific hardware model (Sunmi P2 Mini, PAX A920 Pro …). The
     * FormRequest enforces that the chosen model belongs to the
     * chosen make so we never end up with a Sunmi device pinned to
     * a PAX model row.
     *
     * @return BelongsTo<DeviceModel, $this>
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(DeviceModel::class, 'model_id');
    }

    /**
     * The commission profile this device is bound to. Read from the
     * shared `commission_profiles` table (owned by the charity app);
     * the POS admin only picks from the list, never writes to it.
     * Drives the round-up donation split calculation when card
     * payments land.
     *
     * @return BelongsTo<CommissionProfile, $this>
     */
    public function commissionProfile(): BelongsTo
    {
        return $this->belongsTo(CommissionProfile::class, 'commission_profile_id');
    }

    /**
     * The acquiring bank this device is bound to. Read from the
     * shared `banks` table (owned by the charity app); the POS
     * admin only picks from the list, never writes to it.
     *
     * Combined with {@see terminal_id}, this is the routing key the
     * bank reconciler uses to verify card transactions back to the
     * right merchant.
     *
     * @return BelongsTo<Bank, $this>
     */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }

    /**
     * The beneficiary organization this device's card round-up donations go to.
     * Read from the shared `organizations` table (owned by the charity app);
     * the POS admin only picks from the list, never writes to it. Carried onto
     * the charity_transaction when a round-up is recorded.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    /**
     * Append-only ledger of (de)assignment events. The newest row with
     * `unassigned_at IS NULL` represents the current assignment; closed
     * rows are the history shown on the Device Detail page.
     *
     * @return HasMany<DeviceAssignmentHistory, $this>
     */
    public function assignmentHistory(): HasMany
    {
        return $this->hasMany(DeviceAssignmentHistory::class)
            ->orderByDesc('assigned_at');
    }
}
