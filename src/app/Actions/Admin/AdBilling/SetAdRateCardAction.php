<?php

declare(strict_types=1);

namespace App\Actions\Admin\AdBilling;

use App\Models\Advertiser;
use App\Models\AdRateCard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase 5 — set an advertiser's ACTIVE rate card. The previous active
 * card is deactivated (kept as pricing history); issued invoices are
 * untouched — they snapshot their pricing at issue time.
 */
final class SetAdRateCardAction
{
    public function handle(int $advertiserId, string $pricingModel, string $rate, ?int $actorId, ?string $notes = null): AdRateCard
    {
        if (! in_array($pricingModel, AdRateCard::MODELS, true)) {
            throw new RuntimeException('Unknown pricing model.');
        }
        $rateValue = (float) $rate;
        if ($rateValue <= 0) {
            throw new RuntimeException('The rate must be a positive amount.');
        }

        return DB::transaction(function () use ($advertiserId, $pricingModel, $rateValue, $actorId, $notes): AdRateCard {
            // Locking the advertiser row serialises concurrent first-time
            // sets (locking the — possibly empty — active-cards set alone
            // would lock nothing in Postgres and mint two active cards).
            $exists = Advertiser::withTrashed()->whereKey($advertiserId)->lockForUpdate()->exists();
            if (! $exists) {
                throw new RuntimeException('Unknown advertiser.');
            }

            AdRateCard::query()
                ->where('advertiser_id', $advertiserId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->get()
                ->each(fn (AdRateCard $card) => $card->forceFill(['is_active' => false])->save());

            return AdRateCard::query()->create([
                'uuid' => (string) Str::uuid(),
                'advertiser_id' => $advertiserId,
                'pricing_model' => $pricingModel,
                'rate' => number_format($rateValue, 3, '.', ''),
                'is_active' => true,
                'notes' => $notes,
                'created_by_user_id' => $actorId,
            ]);
        });
    }
}
