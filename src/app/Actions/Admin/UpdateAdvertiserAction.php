<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Advertiser;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Edit an advertiser's profile + merchant link + status (active / suspended).
 * Email and password are NOT touched here (email is the login id; password has
 * its own reset action). Un-merchanting nulls a real MERCHANT company link so
 * a non-merchant can't keep a dangling pos_companies id — but a dedicated
 * advertising-only company (is_advertiser_only) is the advertiser's own record
 * and is preserved.
 */
final readonly class UpdateAdvertiserAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  Already validated; only the
     *                                            keys present are applied.
     */
    public function handle(Advertiser $advertiser, array $attributes, ?User $actor = null): Advertiser
    {
        return DB::transaction(function () use ($advertiser, $attributes, $actor): Advertiser {
            $old = $advertiser->only([
                'name', 'brand_name', 'phone', 'status',
                'is_merchant', 'company_id', 'category',
            ]);

            $advertiser->fill(array_intersect_key($attributes, array_flip([
                'name', 'brand_name', 'phone', 'status',
                'is_merchant', 'company_id', 'category',
            ])));

            // Re-evaluate the company link only when the caller actually sent
            // is_merchant or company_id. An edit that omits both (e.g. tweaking
            // an advertising-only advertiser's profile) leaves the link alone.
            if (array_key_exists('is_merchant', $attributes) || array_key_exists('company_id', $attributes)) {
                // A non-merchant keeps a link ONLY when it points at its own
                // advertising-only company; a real merchant link is dropped.
                if (! $advertiser->is_merchant && $advertiser->company_id !== null) {
                    $linked = Company::find($advertiser->company_id);
                    if ($linked === null || ! $linked->is_advertiser_only) {
                        $advertiser->company_id = null;
                    }
                }
            }

            $advertiser->save();

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'advertiser.updated',
                actorUserId: $actor?->id,
                auditableType: Advertiser::class,
                auditableId: $advertiser->id,
                oldValues: $old,
                newValues: $advertiser->only([
                    'name', 'brand_name', 'phone', 'status',
                    'is_merchant', 'company_id', 'category',
                ]),
            ));

            return $advertiser;
        });
    }
}
