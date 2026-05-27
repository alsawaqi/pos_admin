<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Read-only mirror of pos_merchant's RestockRequest. Schema
 * owned by pos_admin's 2026_05_31_010100 migration; the full
 * lifecycle (submit / approve / reject / cancel / allocate)
 * lives on the merchant side in Phase 5c.
 *
 * status is one of:
 *   draft → submitted → approved → fulfilled (terminal)
 *                                 → rejected  (terminal)
 *   draft or submitted → cancelled (terminal)
 */
#[Fillable([])]
class RestockRequest extends Model
{
    protected $table = 'pos_restock_requests';

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'fulfilled_at' => 'datetime',
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
     * @return HasMany<RestockRequestLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RestockRequestLine::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
