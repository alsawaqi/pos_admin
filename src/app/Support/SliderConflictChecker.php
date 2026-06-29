<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Advertiser;
use App\Models\Branch;
use App\Models\Company;

/**
 * Competitor advisory for the slider builder.
 *
 * A merchant's "category" is derived from the advertiser(s) LINKED to that
 * merchant company (is_merchant onboarding) — e.g. a coffee brand that is also a
 * POS merchant tags both its advertiser AND, transitively, its store. A slider
 * conflicts when it carries a DIFFERENT advertiser in the same category as a
 * target branch's merchant. Advisory only — never blocks the save.
 */
final class SliderConflictChecker
{
    /**
     * @param  list<int>  $advertiserIds  advertisers whose content is in the slider
     * @param  list<int>  $branchIds       target branch ids
     * @return list<array{category: string, advertiser_brand: string, competitor_brand: string, merchant_name: string|null, branch_count: int}>
     */
    public function check(array $advertiserIds, array $branchIds): array
    {
        $advertiserIds = array_values(array_unique(array_filter($advertiserIds)));
        $branchIds = array_values(array_unique(array_filter($branchIds)));

        if ($advertiserIds === [] || $branchIds === []) {
            return [];
        }

        $sliderAdvertisers = Advertiser::query()
            ->whereIn('id', $advertiserIds)
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->get(['id', 'brand_name', 'category', 'company_id']);

        if ($sliderAdvertisers->isEmpty()) {
            return [];
        }

        $branches = Branch::query()->withoutTenantScope()
            ->whereIn('id', $branchIds)
            ->whereNotNull('company_id')
            ->get(['id', 'company_id']);

        if ($branches->isEmpty()) {
            return [];
        }

        $companyIds = $branches->pluck('company_id')->unique()->values()->all();
        $branchCountByCompany = $branches->groupBy('company_id')->map->count();

        // The competing merchants: advertisers linked to those target companies.
        $merchantsByCompany = Advertiser::query()
            ->whereIn('company_id', $companyIds)
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->get(['id', 'brand_name', 'category', 'company_id'])
            ->groupBy('company_id');

        // Company is the tenant root (not tenant-scoped) — plain query.
        $companyNames = Company::query()
            ->whereIn('id', $companyIds)
            ->pluck('name', 'id');

        $conflicts = [];

        foreach ($companyIds as $companyId) {
            $merchants = $merchantsByCompany->get($companyId);
            if ($merchants === null) {
                continue;
            }

            foreach ($merchants as $merchant) {
                foreach ($sliderAdvertisers as $sliderAd) {
                    // Same brand, or the ad IS this merchant's own store → fine.
                    if ($sliderAd->id === $merchant->id) {
                        continue;
                    }
                    if ($sliderAd->company_id !== null && (int) $sliderAd->company_id === (int) $companyId) {
                        continue;
                    }
                    if (strcasecmp(trim((string) $sliderAd->category), trim((string) $merchant->category)) !== 0) {
                        continue;
                    }

                    $key = $sliderAd->id.'|'.$merchant->id.'|'.$companyId;
                    $conflicts[$key] = [
                        'category' => (string) $sliderAd->category,
                        'advertiser_brand' => (string) $sliderAd->brand_name,
                        'competitor_brand' => (string) $merchant->brand_name,
                        'merchant_name' => $companyNames[$companyId] ?? null,
                        'branch_count' => (int) ($branchCountByCompany[$companyId] ?? 1),
                    ];
                }
            }
        }

        return array_values($conflicts);
    }
}
