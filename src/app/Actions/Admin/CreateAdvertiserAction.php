<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Advertiser;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create a marketing-platform advertiser (admin-driven onboarding) + write an
 * audit-log entry. The password is passed in plaintext and hashed by the
 * model's `hashed` cast.
 *
 * `company_id` is honoured whenever it's supplied: a merchant advertiser links
 * to its POS company, and an advertising-only advertiser links to the dedicated
 * `is_advertiser_only` company minted by {@see CreateAdvertiserCompanyAction}.
 * `is_merchant` only records whether that company is a real POS merchant — the
 * existing create modal sends a null company_id for plain non-merchant
 * advertisers, so this stays null for them.
 */
final readonly class CreateAdvertiserAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes, ?User $actor = null): Advertiser
    {
        return DB::transaction(function () use ($attributes, $actor): Advertiser {
            $isMerchant = (bool) ($attributes['is_merchant'] ?? false);

            /** @var Advertiser $advertiser */
            $advertiser = Advertiser::query()->create([
                'name' => $attributes['name'],
                'brand_name' => $attributes['brand_name'],
                'email' => $attributes['email'],
                'password' => $attributes['password'], // hashed by the model cast
                'phone' => $attributes['phone'] ?? null,
                'status' => 'active',
                'is_merchant' => $isMerchant,
                'company_id' => $attributes['company_id'] ?? null,
                'category' => $attributes['category'] ?? null,
            ]);

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'advertiser.created',
                actorUserId: $actor?->id,
                auditableType: Advertiser::class,
                auditableId: $advertiser->id,
                newValues: $advertiser->only([
                    'name', 'brand_name', 'email', 'status',
                    'is_merchant', 'company_id', 'category',
                ]),
            ));

            return $advertiser;
        });
    }
}
