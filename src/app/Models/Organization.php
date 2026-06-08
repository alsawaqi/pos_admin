<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only model over the charity database's `organizations` table (shared
 * Postgres instance per blueprint §3.2).
 *
 * The POS admin app never CREATES or UPDATES rows here — organizations are
 * owned by the charity application. POS uses this model purely to populate the
 * "Organization" dropdown on the Register Device page (the beneficiary org a
 * device's card round-up donations go to) and to render the chosen org's name
 * on the Device Show page.
 *
 * Mirrors {@see Bank} / {@see CommissionProfile} exactly — same read-only
 * guarantees, same shared-DB rationale (charity tables stay unprefixed).
 */
class Organization extends Model
{
    protected $table = 'organizations';

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
     * Scope helper for the dropdown — admins should not be able to pick a
     * deactivated organization when registering a new device.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
