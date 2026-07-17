<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\Company;
use App\Models\MerchantCommissionProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates or replaces a merchant's commission profile.
 *
 * The share lines are fully replaced on every save (the form is the
 * source of truth) and the merchant residual is recomputed from them:
 * merchant_percent = 100 - Σ(share percents). The caller's request has
 * already guaranteed Σ ≤ 100, so the residual is always in [0, 100].
 */
final readonly class UpsertMerchantCommissionProfileAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @param  array<int, array{party_type: string, label: string, percent: int|float|string}>  $shares
     */
    public function handle(Company $company, array $shares, bool $isActive, ?User $actor = null): MerchantCommissionProfile
    {
        return DB::transaction(function () use ($company, $shares, $isActive, $actor): MerchantCommissionProfile {
            /** @var MerchantCommissionProfile $profile */
            $profile = MerchantCommissionProfile::query()->firstOrNew(['company_id' => $company->id]);

            if (! $profile->exists) {
                $profile->uuid = (string) Str::uuid();
            }

            // Replace the share lines wholesale.
            if ($profile->exists) {
                $profile->shares()->delete();
            }

            $cardSum = 0.0;
            $sortOrder = 0;
            $sharePayload = [];

            foreach ($shares as $share) {
                $percent = round((float) $share['percent'], 2);
                // Channel scope: bank lines are inherently card-only; other
                // lines default to 'all'. The request has validated the values.
                $appliesTo = $share['party_type'] === 'bank'
                    ? 'card'
                    : (string) ($share['applies_to'] ?? 'all');
                if ($appliesTo !== 'cash_bank') {
                    $cardSum += $percent;
                }
                $sharePayload[] = [
                    'party_type' => $share['party_type'],
                    'label' => $share['label'],
                    'percent' => $percent,
                    'applies_to' => $appliesTo,
                    'sort_order' => $sortOrder++,
                ];
            }

            $profile->is_active = $isActive;
            // The stored residual is the CARD-channel one (the fullest split —
            // bank rides card). Per-channel residuals are recomputed live in the
            // editor; per-sale amounts are always exact regardless (the merchant
            // row takes the integer remainder at record time).
            $profile->merchant_percent = round(100 - $cardSum, 2);
            $profile->save();

            if ($sharePayload !== []) {
                $profile->shares()->createMany($sharePayload);
            }

            $this->writeAuditLog->handle(new AuditLogData(
                event: 'company.commission_profile.updated',
                actorUserId: $actor?->id,
                companyId: $company->id,
                auditableType: MerchantCommissionProfile::class,
                auditableId: $profile->id,
                newValues: [
                    'is_active' => $isActive,
                    'merchant_percent' => $profile->merchant_percent,
                    'shares' => array_map(static fn (array $s): array => [
                        'party_type' => $s['party_type'],
                        'label' => $s['label'],
                        'percent' => $s['percent'],
                        'applies_to' => $s['applies_to'],
                    ], $sharePayload),
                ],
            ));

            return $profile->load('shares');
        });
    }
}
