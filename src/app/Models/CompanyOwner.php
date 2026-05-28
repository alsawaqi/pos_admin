<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CompanyOwnerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One owner of a merchant {@see Company}. Many companies have more
 * than one (family business, partnership, holding co.) so this lives
 * in its own table instead of being inlined on `pos_companies`.
 *
 * Exactly one owner per company should be flagged `is_primary` —
 * enforced by the FormRequest + Action layer (not by a partial
 * unique index, so the schema stays portable across drivers).
 *
 * The PII fields (civil_id, phone, email) are wrapped in the
 * Laravel `encrypted` cast (Sprint 3, blueprint §9.13.2). Ciphertext
 * is stored at rest; the model surfaces plaintext transparently to
 * callers. Note: this means SQL WHERE on those columns won't match
 * the raw plaintext anymore — the ciphertext is non-deterministic.
 * We don't filter on any of them today, so that's acceptable; if a
 * future feature needs to (e.g. dedupe by email), introduce a
 * hashed lookup column alongside.
 */
class CompanyOwner extends Model
{
    /** @use HasFactory<CompanyOwnerFactory> */
    use HasFactory;

    protected $table = 'pos_company_owners';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'full_name_en',
        'full_name_ar',
        'civil_id',
        'nationality',
        'phone',
        'email',
        'is_primary',
        'ownership_percentage',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            // Decimal as a string preserves precision when the value
            // round-trips through PHP floats; the front-end coerces it
            // back to a number on display.
            'ownership_percentage' => 'decimal:2',
            // Application-layer encryption on PII (Sprint 3). The
            // columns were widened to TEXT in
            // 2026_05_26_030000_widen_pii_columns_for_encryption
            // so the ciphertext (~3× plaintext) always fits.
            'civil_id' => 'encrypted',
            'phone' => 'encrypted',
            'email' => 'encrypted',
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
     * Scope helper for "the primary owner row" — keeps callers tidy:
     *   $company->owners()->primary()->first()
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('is_primary', true);
    }
}
