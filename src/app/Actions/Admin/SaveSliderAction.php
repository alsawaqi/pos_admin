<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Security\WriteAuditLogAction;
use App\Data\Security\AuditLogData;
use App\Models\ContentAsset;
use App\Models\MarketingSlider;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create or update a marketing slider together with its ordered items + its
 * targets, in one transaction. Items snapshot the advertiser_id of each content
 * asset (so grouping + the competitor advisory don't need to re-join the
 * cross-app content_assets table). A full replace is used for items/targets —
 * the builder always sends the complete set.
 */
final readonly class SaveSliderAction
{
    public function __construct(
        private WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): MarketingSlider
    {
        return DB::transaction(function () use ($data, $actor): MarketingSlider {
            $slider = MarketingSlider::query()->create([
                'uuid' => (string) Str::uuid(),
                'name' => $data['name'],
                'loop_interval_seconds' => $data['loop_interval_seconds'] ?? 8,
                'status' => $data['status'] ?? 'draft',
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'created_by_user_id' => $actor?->id,
            ]);

            $this->syncItems($slider, $data['items'] ?? []);
            $this->syncTargets($slider, $data['targets'] ?? []);
            $this->audit('marketing_slider.created', $slider, $actor);

            return $slider->load('items', 'targets');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(MarketingSlider $slider, array $data, ?User $actor = null): MarketingSlider
    {
        return DB::transaction(function () use ($slider, $data, $actor): MarketingSlider {
            $slider->fill(array_intersect_key($data, array_flip([
                'name', 'loop_interval_seconds', 'status', 'starts_at', 'ends_at',
            ])));
            $slider->save();

            if (array_key_exists('items', $data)) {
                $this->syncItems($slider, $data['items'] ?? []);
            }
            if (array_key_exists('targets', $data)) {
                $this->syncTargets($slider, $data['targets'] ?? []);
            }

            $this->audit('marketing_slider.updated', $slider, $actor);

            return $slider->load('items', 'targets');
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(MarketingSlider $slider, array $items): void
    {
        $slider->items()->delete();

        $assetIds = array_values(array_unique(array_map(
            static fn (array $i): int => (int) $i['content_asset_id'],
            $items,
        )));
        $advByAsset = ContentAsset::query()->whereIn('id', $assetIds)->pluck('advertiser_id', 'id');

        foreach (array_values($items) as $index => $item) {
            $assetId = (int) $item['content_asset_id'];
            $slider->items()->create([
                'content_asset_id' => $assetId,
                'advertiser_id' => $advByAsset[$assetId] ?? null,
                'sort_order' => $index,
                'duration_seconds' => $item['duration_seconds'] ?? $slider->loop_interval_seconds,
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $targets
     */
    private function syncTargets(MarketingSlider $slider, array $targets): void
    {
        $slider->targets()->delete();

        foreach ($targets as $target) {
            $branchId = $target['branch_id'] ?? null;
            $deviceId = $target['device_id'] ?? null;

            // A row with neither column is meaningless (= "all branches", which
            // is the absence of any target row). Skip it.
            if ($branchId === null && $deviceId === null) {
                continue;
            }

            $slider->targets()->create([
                'branch_id' => $branchId,
                'device_id' => $deviceId,
            ]);
        }
    }

    private function audit(string $event, MarketingSlider $slider, ?User $actor): void
    {
        $this->writeAuditLog->handle(new AuditLogData(
            event: $event,
            actorUserId: $actor?->id,
            auditableType: MarketingSlider::class,
            auditableId: $slider->id,
            newValues: $slider->only(['name', 'status', 'loop_interval_seconds']),
        ));
    }
}
