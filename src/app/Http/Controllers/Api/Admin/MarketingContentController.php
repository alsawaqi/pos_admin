<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Actions\Admin\ReviewContentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectContentRequest;
use App\Http\Resources\Admin\MarketingContentResource;
use App\Models\Advertiser;
use App\Models\ContentAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * Content review for the marketing platform. Advertisers submit content
 * (status=pending) on the marketing portal; the admin approves it (eligible for
 * sliders) or rejects it with a note. `content_assets` is owned by the
 * marketing-api app (shared charity_db); pos_admin only writes the review
 * fields. Gated by marketing.content.review (ContentAssetPolicy). Routes under
 * /admin/api/v1/marketing/content.
 */
class MarketingContentController extends Controller
{
    /** Statuses that count as "reviewed" for the Reviewed tab. */
    private const REVIEWED = ['approved', 'live', 'expired', 'rejected'];

    public function __construct(
        private readonly ReviewContentAction $review,
    ) {}

    /**
     * GET /admin/api/v1/marketing/content/submitters
     *
     * The advertisers who have submitted content, with their pending count — the
     * landing list for review (click an advertiser to see just their content).
     */
    public function submitters(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ContentAsset::class);

        // Aggregate each advertiser's submitted content (anything past draft).
        $rows = ContentAsset::query()
            ->whereNotNull('advertiser_id')
            ->where('status', '!=', 'draft')
            ->selectRaw('advertiser_id')
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when status = 'pending' then 1 else 0 end) as pending_count")
            ->selectRaw('max(submitted_at) as last_submitted_at')
            ->groupBy('advertiser_id')
            ->get();

        $advertisers = Advertiser::query()
            ->whereIn('id', $rows->pluck('advertiser_id')->all())
            ->get(['id', 'name', 'brand_name', 'status'])
            ->keyBy('id');

        $data = $rows
            ->map(function ($row) use ($advertisers): ?array {
                $adv = $advertisers->get((int) $row->advertiser_id);
                if ($adv === null) {
                    return null; // orphaned advertiser_id — skip
                }

                return [
                    'advertiser_id' => (int) $row->advertiser_id,
                    'brand_name' => $adv->brand_name,
                    'name' => $adv->name,
                    'status' => $adv->status,
                    'pending_count' => (int) $row->pending_count,
                    'total' => (int) $row->total,
                    'last_submitted_at' => $row->last_submitted_at
                        ? Carbon::parse($row->last_submitted_at)->toIso8601String()
                        : null,
                ];
            })
            ->filter()
            ->sortByDesc(fn (array $r): array => [$r['pending_count'], $r['last_submitted_at'] ?? ''])
            ->values();

        return response()->json(['data' => $data]);
    }

    /**
     * GET /admin/api/v1/marketing/content?view=pending|reviewed&advertiser_id=
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ContentAsset::class);

        $query = ContentAsset::query()->with('advertiser');

        if ($request->string('view')->value() === 'reviewed') {
            $query->whereIn('status', self::REVIEWED);
        } else {
            $query->where('status', 'pending');
        }

        if ($request->filled('advertiser_id')) {
            $query->where('advertiser_id', (int) $request->input('advertiser_id'));
        }

        return MarketingContentResource::collection(
            $query->orderByDesc('submitted_at')->orderByDesc('id')->limit(200)->get(),
        );
    }

    /**
     * POST /admin/api/v1/marketing/content/{contentAsset}/approve
     */
    public function approve(Request $request, ContentAsset $contentAsset): MarketingContentResource
    {
        $this->authorize('review', $contentAsset);
        abort_unless($contentAsset->isPending(), 422, 'Only pending content can be reviewed.');

        $updated = $this->review->approve($contentAsset, $request->user());

        return MarketingContentResource::make($updated->load('advertiser'));
    }

    /**
     * POST /admin/api/v1/marketing/content/{contentAsset}/reject
     */
    public function reject(RejectContentRequest $request, ContentAsset $contentAsset): MarketingContentResource
    {
        $this->authorize('review', $contentAsset);
        abort_unless($contentAsset->isPending(), 422, 'Only pending content can be reviewed.');

        $updated = $this->review->reject($contentAsset, $request->validated()['note'] ?? null, $request->user());

        return MarketingContentResource::make($updated->load('advertiser'));
    }
}
