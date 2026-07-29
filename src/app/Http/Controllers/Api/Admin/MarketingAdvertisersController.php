<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\CreateAdvertiserAction;
use App\Actions\Admin\CreateAdvertiserCompanyAction;
use App\Actions\Admin\ResetAdvertiserPasswordAction;
use App\Actions\Admin\SyncCompanyActivitiesAction;
use App\Actions\Admin\UpdateAdvertiserAction;
use App\Actions\Admin\UpdateCompanyAction;
use App\Data\Admin\CompanyActivitySelectionData;
use App\Data\Admin\UpdateCompanyData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdvertiserCompanyRequest;
use App\Http\Requests\Admin\StoreAdvertiserRequest;
use App\Http\Requests\Admin\UpdateAdvertiserCompanyRequest;
use App\Http\Requests\Admin\UpdateAdvertiserRequest;
use App\Http\Resources\Admin\AdvertiserDetailResource;
use App\Http\Resources\Admin\AdvertiserResource;
use App\Models\Advertiser;
use App\Models\ContentAsset;
use App\Models\MarketingSliderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Admin-driven advertiser onboarding for the marketing platform.
 *
 * The `advertisers` row lives in the shared charity_db (owned by the
 * marketing-api app); pos_admin creates the account + login, links it to a
 * merchant company when applicable, suspends/reactivates, and resets the
 * password. The advertiser then logs in on the marketing portal to upload
 * content. Every endpoint requires marketing.advertisers.manage
 * (AdvertiserPolicy). Routes in routes/admin.php under
 * /admin/api/v1/marketing/advertisers.
 */
class MarketingAdvertisersController extends Controller
{
    public function __construct(
        private readonly CreateAdvertiserAction $createAdvertiser,
        private readonly CreateAdvertiserCompanyAction $createAdvertiserCompany,
        private readonly UpdateAdvertiserAction $updateAdvertiser,
        private readonly ResetAdvertiserPasswordAction $resetPassword,
        private readonly UpdateCompanyAction $updateCompany,
        private readonly SyncCompanyActivitiesAction $syncActivities,
    ) {}

    /**
     * GET /admin/api/v1/marketing/advertisers/{advertiser} — full detail for
     * the admin detail page: the advertiser + its linked company (owners +
     * activities) + a content-status summary.
     */
    public function show(Advertiser $advertiser): AdvertiserDetailResource
    {
        $this->authorize('view', $advertiser);

        $advertiser->load(['company' => fn ($q) => $q->with(['owners', 'activities'])]);
        $advertiser->setAttribute('content_stats', $this->contentStats($advertiser->id));

        return AdvertiserDetailResource::make($advertiser);
    }

    /**
     * GET /admin/api/v1/marketing/advertisers/{advertiser}/delivery — the
     * Status/delivery tab: where this advertiser's content actually RUNS.
     * Every slider placement with a DERIVED state (live = active slider whose
     * window covers now / scheduled = future window / ended = nothing current
     * or upcoming) + target reach, plus real play statistics from the device
     * telemetry (pos_marketing_impressions, written by pos_api). Assets that
     * are neither placed nor ever played are omitted — the Content tab
     * already lists the plain library.
     */
    public function delivery(Advertiser $advertiser): JsonResponse
    {
        $this->authorize('view', $advertiser);

        $assets = ContentAsset::query()
            ->where('advertiser_id', $advertiser->id)
            ->orderBy('title')
            ->get();
        $assetIds = $assets->pluck('id')->all() ?: [0];

        $items = MarketingSliderItem::query()
            ->whereIn('content_asset_id', $assetIds)
            ->with(['slider.targets'])
            ->get();

        $stats = DB::table('pos_marketing_impressions')
            ->whereIn('content_asset_id', $assetIds)
            ->groupBy('content_asset_id')
            ->selectRaw('content_asset_id, COUNT(*) AS plays, COALESCE(SUM(play_duration_ms), 0) AS play_ms, COUNT(DISTINCT device_id) AS devices, MAX(played_at) AS last_played_at')
            ->get()
            ->keyBy('content_asset_id');

        $now = now();
        $rank = ['live' => 0, 'scheduled' => 1, 'ended' => 2];
        $out = [];
        foreach ($assets as $asset) {
            $placements = $items
                ->where('content_asset_id', $asset->id)
                ->map(function (MarketingSliderItem $item) use ($now): ?array {
                    $slider = $item->slider;
                    if ($slider === null) {
                        return null; // slider soft-deleted — placement gone
                    }
                    $active = (string) $slider->status === 'active';
                    $starts = $slider->starts_at;
                    $ends = $slider->ends_at;
                    $live = $active
                        && ($starts === null || $starts->lte($now))
                        && ($ends === null || $ends->gte($now));
                    $scheduled = ! $live && $active && $starts !== null && $starts->gt($now);
                    $targets = $slider->targets;

                    return [
                        'slider_uuid' => $slider->uuid,
                        'slider_name' => $slider->name,
                        'slider_status' => (string) $slider->status,
                        'starts_at' => $starts?->toIso8601String(),
                        'ends_at' => $ends?->toIso8601String(),
                        'state' => $live ? 'live' : ($scheduled ? 'scheduled' : 'ended'),
                        // No target rows = the slider plays everywhere.
                        'everywhere' => $targets->isEmpty(),
                        'device_count' => $targets->whereNotNull('device_id')->unique('device_id')->count(),
                    ];
                })
                ->filter()
                ->sortBy(fn (array $p): int => $rank[$p['state']])
                ->values();

            $s = $stats->get($asset->id);
            if ($placements->isEmpty() && $s === null) {
                continue;
            }

            $out[] = [
                'id' => $asset->id,
                'title' => $asset->title,
                'type' => $asset->type,
                'status' => $asset->status,
                'thumbnail_url' => $asset->thumbnail_public_url ?? $asset->public_url,
                'state' => $placements->first()['state'] ?? 'ended',
                'placements' => $placements->all(),
                'stats' => $s === null ? null : [
                    'plays' => (int) $s->plays,
                    'play_seconds' => (int) round(((int) $s->play_ms) / 1000),
                    'devices' => (int) $s->devices,
                    'last_played_at' => $s->last_played_at,
                ],
            ];
        }

        usort($out, fn (array $a, array $b): int => $rank[$a['state']] <=> $rank[$b['state']]);

        return response()->json(['data' => ['assets' => $out]]);
    }

    /**
     * PATCH /admin/api/v1/marketing/advertisers/{advertiser}/company — edit the
     * linked company's info (name, CR, contact, owners). Only an advertising-only
     * company is editable here; a real merchant is managed on the Merchants page.
     */
    public function updateCompany(UpdateAdvertiserCompanyRequest $request, Advertiser $advertiser): AdvertiserDetailResource
    {
        $this->authorize('update', $advertiser);

        $company = $advertiser->company;
        abort_unless(
            $company !== null && $company->is_advertiser_only,
            422,
            'Only an advertising-only company can be edited here.',
        );

        $this->updateCompany->handle($company, UpdateCompanyData::from($request->validated()), $request->user());

        return $this->detailFor($advertiser);
    }

    /**
     * PUT /admin/api/v1/marketing/advertisers/{advertiser}/activities — replace
     * the advertising-only company's business activities.
     */
    public function syncCompanyActivities(Request $request, Advertiser $advertiser): AdvertiserDetailResource
    {
        $this->authorize('update', $advertiser);

        $company = $advertiser->company;
        abort_unless(
            $company !== null && $company->is_advertiser_only,
            422,
            'Only an advertising-only company can be edited here.',
        );

        $validated = $request->validate([
            'activities' => ['present', 'array'],
            'activities.*.business_activity_id' => ['required', 'integer', 'exists:pos_business_activities,id'],
            'activities.*.is_primary' => ['nullable', 'boolean'],
        ]);

        $selections = collect($validated['activities'])
            ->map(fn (array $a): CompanyActivitySelectionData => CompanyActivitySelectionData::from($a))
            ->all();

        $this->syncActivities->handle($company, $selections, $request->user());

        return $this->detailFor($advertiser);
    }

    /** Reload + project a fresh advertiser detail payload. */
    private function detailFor(Advertiser $advertiser): AdvertiserDetailResource
    {
        $advertiser->load(['company' => fn ($q) => $q->with(['owners', 'activities'])]);
        $advertiser->setAttribute('content_stats', $this->contentStats($advertiser->id));

        return AdvertiserDetailResource::make($advertiser);
    }

    /**
     * @return array{total: int, pending: int, approved: int, rejected: int}
     */
    private function contentStats(int $advertiserId): array
    {
        $row = ContentAsset::query()
            ->where('advertiser_id', $advertiserId)
            ->where('status', '!=', 'draft')
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when status = 'pending' then 1 else 0 end) as pending")
            ->selectRaw("sum(case when status = 'approved' then 1 else 0 end) as approved")
            ->selectRaw("sum(case when status = 'rejected' then 1 else 0 end) as rejected")
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'approved' => (int) ($row->approved ?? 0),
            'rejected' => (int) ($row->rejected ?? 0),
        ];
    }

    /**
     * GET /admin/api/v1/marketing/advertisers
     *
     * Optional filters: status (active|suspended), merchants_only=1, search
     * (brand / contact name / email).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Advertiser::class);

        $query = Advertiser::query()->with('company');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->value());
        }

        if ($request->boolean('merchants_only')) {
            $query->where('is_merchant', true);
        }

        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $query->where(function ($q) use ($term): void {
                $q->where('brand_name', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
            });
        }

        return AdvertiserResource::collection(
            $query->orderByDesc('id')->get(),
        );
    }

    /**
     * POST /admin/api/v1/marketing/advertisers — create the account + login.
     */
    public function store(StoreAdvertiserRequest $request): JsonResponse
    {
        $this->authorize('create', Advertiser::class);

        $advertiser = $this->createAdvertiser->handle($request->validated(), $request->user());

        return AdvertiserResource::make($advertiser->load('company'))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * POST /admin/api/v1/marketing/advertisers/with-company — onboard a NEW
     * advertising-only company (trade name, CR, owners, activities) plus its
     * marketing portal login, in one step. The wizard for advertisers who are
     * NOT existing POS merchants.
     */
    public function storeWithCompany(StoreAdvertiserCompanyRequest $request): JsonResponse
    {
        $this->authorize('create', Advertiser::class);

        ['advertiser' => $advertiser] = $this->createAdvertiserCompany->handle(
            $request->validated(),
            $request->user(),
        );

        return AdvertiserResource::make($advertiser->load('company'))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PATCH /admin/api/v1/marketing/advertisers/{advertiser} — edit profile,
     * merchant link, or status (active / suspended).
     */
    public function update(UpdateAdvertiserRequest $request, Advertiser $advertiser): AdvertiserResource
    {
        $this->authorize('update', $advertiser);

        $updated = $this->updateAdvertiser->handle($advertiser, $request->validated(), $request->user());

        return AdvertiserResource::make($updated->load('company'));
    }

    /**
     * POST /admin/api/v1/marketing/advertisers/{advertiser}/reset-password
     *
     * Generates a new password and returns the plaintext ONCE for the admin to
     * hand over.
     */
    public function resetPassword(Request $request, Advertiser $advertiser): JsonResponse
    {
        $this->authorize('update', $advertiser);

        $plain = $this->resetPassword->handle($advertiser, $request->user());

        return response()->json(['data' => ['password' => $plain]]);
    }
}
