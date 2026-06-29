<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\DeleteSliderAction;
use App\Actions\Admin\SaveSliderAction;
use App\Events\MarketingSliderChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSliderRequest;
use App\Http\Requests\Admin\UpdateSliderRequest;
use App\Http\Resources\Admin\SliderResource;
use App\Models\Branch;
use App\Models\ContentAsset;
use App\Models\Device;
use App\Models\MarketingSlider;
use App\Models\MarketingSliderTarget;
use App\Support\SliderConflictChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * The slider builder. The admin groups approved advertiser content into an
 * ordered loop and targets it at branches / devices (none = all branches).
 * pos_admin owns the slider tables; pos_api reads them for /device/config.
 * Gated by marketing.sliders.manage (MarketingSliderPolicy). Routes under
 * /admin/api/v1/marketing/sliders.
 */
class MarketingSlidersController extends Controller
{
    /** Eager-loads for the full slider payload. */
    private const WITH = ['items.contentAsset', 'items.advertiser', 'targets.branch', 'targets.device'];

    public function __construct(
        private readonly SaveSliderAction $save,
        private readonly DeleteSliderAction $delete,
        private readonly SliderConflictChecker $conflictChecker,
    ) {}

    /**
     * POST /admin/api/v1/marketing/sliders/check-conflicts
     *
     * Advisory competitor check — given the advertisers in the slider + the
     * target branches, return any conflicts (an ad would land on a competing
     * merchant's screen). Never blocks; the builder shows it as a warning.
     */
    public function checkConflicts(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MarketingSlider::class);

        $data = $request->validate([
            'advertiser_ids' => ['array'],
            'advertiser_ids.*' => ['integer'],
            'branch_ids' => ['array'],
            'branch_ids.*' => ['integer'],
        ]);

        $conflicts = $this->conflictChecker->check(
            $data['advertiser_ids'] ?? [],
            $data['branch_ids'] ?? [],
        );

        return response()->json(['data' => ['conflicts' => $conflicts]]);
    }

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MarketingSlider::class);

        return SliderResource::collection(
            MarketingSlider::query()->withCount(['items', 'targets'])->orderByDesc('id')->get(),
        );
    }

    /**
     * GET /admin/api/v1/marketing/sliders/options
     *
     * Everything the builder needs to pick from: approved content (across all
     * advertisers) + branches + devices (platform-wide, all merchants).
     */
    public function options(): JsonResponse
    {
        $this->authorize('viewAny', MarketingSlider::class);

        $content = ContentAsset::query()
            ->with('advertiser')
            ->whereIn('status', ['approved', 'live'])
            ->orderByDesc('submitted_at')->orderByDesc('id')
            ->limit(300)->get()
            ->map(fn (ContentAsset $a): array => [
                'id' => $a->id,
                'title' => $a->title,
                'type' => $a->type,
                'status' => $a->status,
                'url' => $a->public_url,
                'thumbnail_url' => $a->thumbnail_public_url,
                'duration_seconds' => $a->duration_seconds,
                'advertiser_id' => $a->advertiser_id,
                'advertiser' => $a->advertiser === null ? null : [
                    'id' => $a->advertiser->id,
                    'brand_name' => $a->advertiser->brand_name,
                ],
            ]);

        $branchModels = Branch::query()->withoutTenantScope()->orderBy('name')
            ->get(['id', 'uuid', 'name', 'company_id']);
        $branchNameById = $branchModels->pluck('name', 'id');

        $branches = $branchModels->map(fn (Branch $b): array => [
            'id' => $b->id,
            'uuid' => $b->uuid,
            'name' => $b->name,
            'company_id' => $b->company_id,
        ]);

        // Devices already targeted by another (non-deleted) slider — surfaced as
        // an informational "in use" hint (not a hard filter; the admin can still
        // pick them). whereHas('slider') excludes soft-deleted sliders.
        $usedDeviceIds = MarketingSliderTarget::query()
            ->whereNotNull('device_id')
            ->whereHas('slider')
            ->pluck('device_id')
            ->flip();

        $devices = Device::query()->withoutTenantScope()->orderBy('name')
            ->get(['id', 'uuid', 'name', 'label', 'branch_id', 'status'])
            ->map(fn (Device $d): array => [
                'id' => $d->id,
                'uuid' => $d->uuid,
                'name' => $d->name ?: $d->label,
                'branch_id' => $d->branch_id,
                'branch_name' => $d->branch_id !== null ? ($branchNameById[$d->branch_id] ?? null) : null,
                'status' => $d->status instanceof \BackedEnum ? $d->status->value : (string) $d->status,
                'in_use' => $usedDeviceIds->has($d->id),
            ]);

        return response()->json(['data' => [
            'content' => $content,
            'branches' => $branches,
            'devices' => $devices,
        ]]);
    }

    public function show(MarketingSlider $slider): SliderResource
    {
        $this->authorize('view', $slider);

        $slider->load(self::WITH)->loadCount(['items', 'targets']);

        return SliderResource::make($slider);
    }

    /**
     * GET /admin/api/v1/marketing/sliders/{slider:uuid}/audience
     *
     * Anonymous audience analytics for one slider, aggregated from device
     * telemetry in pos_marketing_impressions (play-time + on-device camera face
     * counts). All metrics are AGGREGATE counts — never images or identities.
     * pos_api writes the table; pos_admin reads the shared charity_db directly.
     */
    public function audience(MarketingSlider $slider): JsonResponse
    {
        $this->authorize('view', $slider);

        $base = DB::table('pos_marketing_impressions')->where('slider_id', $slider->id);

        $summary = (clone $base)->selectRaw(
            'count(*) as plays, '.
            'coalesce(sum(play_duration_ms), 0) as play_ms, '.
            'count(viewers_distinct) as measured_plays, '.
            'coalesce(sum(viewers_distinct), 0) as viewers_distinct, '.
            'coalesce(max(viewers_peak), 0) as viewers_peak, '.
            'coalesce(round(avg(viewers_avg)), 0) as viewers_avg, '.
            'coalesce(sum(attention_ms), 0) as attention_ms'
        )->first();

        $byBranchRows = (clone $base)
            ->selectRaw('branch_id, count(*) as plays, coalesce(sum(viewers_distinct), 0) as viewers, coalesce(sum(attention_ms), 0) as attention_ms')
            ->groupBy('branch_id')
            ->get();

        $branchNames = Branch::query()->withoutTenantScope()
            ->whereIn('id', $byBranchRows->pluck('branch_id')->filter()->values()->all())
            ->pluck('name', 'id');

        $byBranch = $byBranchRows->map(fn ($r): array => [
            'branch_id' => $r->branch_id !== null ? (int) $r->branch_id : null,
            'branch_name' => $r->branch_id !== null
                ? ($branchNames[$r->branch_id] ?? ('Branch #'.$r->branch_id))
                : 'Unassigned',
            'plays' => (int) $r->plays,
            'viewers' => (int) $r->viewers,
            'attention_seconds' => (int) round(((int) $r->attention_ms) / 1000),
        ])->sortByDesc('viewers')->values();

        $timeline = (clone $base)
            ->where('played_at', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw('date(played_at) as d, count(*) as plays, coalesce(sum(viewers_distinct), 0) as viewers')
            ->groupByRaw('date(played_at)')
            ->orderBy('d')
            ->get()
            ->map(fn ($r): array => [
                'date' => (string) $r->d,
                'plays' => (int) $r->plays,
                'viewers' => (int) $r->viewers,
            ])->values();

        return response()->json(['data' => [
            'slider' => ['uuid' => $slider->uuid, 'name' => $slider->name],
            'summary' => [
                'plays' => (int) $summary->plays,
                'play_seconds' => (int) round(((int) $summary->play_ms) / 1000),
                'measured_plays' => (int) $summary->measured_plays,
                'viewers_distinct' => (int) $summary->viewers_distinct,
                'viewers_peak' => (int) $summary->viewers_peak,
                'viewers_avg' => (int) $summary->viewers_avg,
                'attention_seconds' => (int) round(((int) $summary->attention_ms) / 1000),
            ],
            'by_branch' => $byBranch,
            'timeline' => $timeline,
        ]]);
    }

    public function store(StoreSliderRequest $request): JsonResponse
    {
        $this->authorize('create', MarketingSlider::class);

        $slider = $this->save->create($request->validated(), $request->user());
        $slider->load(self::WITH)->loadCount(['items', 'targets']);
        $this->broadcastSliderChange($slider);

        return SliderResource::make($slider)->response()->setStatusCode(201);
    }

    public function update(UpdateSliderRequest $request, MarketingSlider $slider): SliderResource
    {
        $this->authorize('update', $slider);

        $updated = $this->save->update($slider, $request->validated(), $request->user());
        $updated->load(self::WITH)->loadCount(['items', 'targets']);
        $this->broadcastSliderChange($updated);

        return SliderResource::make($updated);
    }

    public function destroy(Request $request, MarketingSlider $slider): JsonResponse
    {
        $this->authorize('delete', $slider);

        $this->delete->handle($slider, $request->user());
        // Targets survive the soft-delete, so the affected branches still
        // resolve — their devices refresh and drop the now-gone slider.
        $this->broadcastSliderChange($slider);

        return response()->json(null, 204);
    }

    /**
     * Phase 3 — push a real-time refresh to the devices a slider reaches. Fired
     * AFTER the DB write commits (post-action). Best-effort: a broadcast failure
     * never breaks the admin request — the device still heals on its next
     * periodic config sync.
     */
    private function broadcastSliderChange(MarketingSlider $slider): void
    {
        $branchIds = $this->affectedBranchIds($slider);
        if ($branchIds === []) {
            return;
        }

        try {
            event(new MarketingSliderChanged($branchIds));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * The branch ids whose devices should refresh for this slider: each target's
     * branch (device-specific targets carry the branch too), plus the branch of
     * any device-only target. No targets = plays everywhere → every branch that
     * has a device.
     *
     * @return list<int>
     */
    private function affectedBranchIds(MarketingSlider $slider): array
    {
        $targets = $slider->loadMissing('targets')->targets;

        if ($targets->isEmpty()) {
            return Device::query()->withoutTenantScope()
                ->whereNotNull('branch_id')->distinct()->pluck('branch_id')
                ->map(static fn ($id): int => (int) $id)->all();
        }

        $branchIds = $targets->whereNotNull('branch_id')->pluck('branch_id');

        $deviceOnly = $targets->whereNull('branch_id')->whereNotNull('device_id')->pluck('device_id');
        if ($deviceOnly->isNotEmpty()) {
            $branchIds = $branchIds->merge(
                Device::query()->withoutTenantScope()
                    ->whereIn('id', $deviceOnly->all())
                    ->whereNotNull('branch_id')->pluck('branch_id'),
            );
        }

        return $branchIds->map(static fn ($id): int => (int) $id)->unique()->values()->all();
    }
}
