<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only model over the charity database's `banks` table (shared
 * Postgres instance per blueprint §3.2).
 *
 * The POS admin app never CREATES or UPDATES rows here — those are
 * owned by the charity application. POS uses this model purely to
 * populate the "Bank" dropdown on the Register Device page and to
 * render the chosen bank's name on the Device Show page.
 *
 * Why no `pos_` table prefix:
 *   `banks` already lives in the shared DB with the plain unprefixed
 *   name (it's owned by the charity app). We intentionally do NOT
 *   rename it — see the Sprint 0 plan: charity tables stay as-is,
 *   only the POS tables carry the `pos_` prefix.
 *
 * Mirrors {@see CommissionProfile} exactly — same pattern, same
 * guarantees. If we ever need to add new behaviour (e.g. caching
 * the dropdown source) put it on a Concern shared by both rather
 * than duplicating.
 */
class Bank extends Model
{
    protected $table = 'banks';

    /**
     * Read-only. Empty fillable + explicit guard keeps any accidental
     * mass-assignment from this app from mutating charity data.
     */
    protected $fillable = [];
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope helper for the dropdown — admins should not be able to
     * pick a retired bank when registering a new device.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
