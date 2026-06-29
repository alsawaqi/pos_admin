<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketingSlider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Admin slider-media uploads. The admin can drop an image/video straight into a
 * slider instead of only picking approved advertiser content. pos_admin
 * forwards the file to marketing-api over charity_net (shared internal token)
 * so it lands in the SAME content store advertiser uploads use — device-
 * reachable at MARKETING_PUBLIC_URL. The created asset is platform-owned
 * (advertiser_id null) + pre-approved, so it appears in the builder's content
 * picker. `replace` is the Filerobot editor's save-back. Gated by
 * marketing.sliders.manage (same as the builder).
 */
class MarketingContentUploadController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        $this->authorize('create', MarketingSlider::class);

        $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'file' => ['required', 'file', 'max:51200', 'mimes:jpg,jpeg,png,mp4'],
        ]);

        $file = $request->file('file');

        $res = $this->client()
            ->attach('file', fopen($file->getRealPath(), 'r'), $file->getClientOriginalName())
            ->post($this->endpoint('/api/admin/content-assets'), [
                'title' => (string) $request->input('title'),
            ]);

        return response()->json($res->json() ?? ['message' => 'Upload failed.'], $res->status());
    }

    public function replace(Request $request, int $assetId): JsonResponse
    {
        $this->authorize('create', MarketingSlider::class);

        $request->validate([
            'file' => ['required', 'file', 'max:51200', 'mimes:jpg,jpeg,png'],
        ]);

        $file = $request->file('file');

        $res = $this->client()
            ->attach('file', fopen($file->getRealPath(), 'r'), $file->getClientOriginalName())
            ->post($this->endpoint("/api/admin/content-assets/{$assetId}/replace"));

        return response()->json($res->json() ?? ['message' => 'Save failed.'], $res->status());
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders([
            'X-Internal-Token' => (string) config('services.internal.content_token'),
            'Accept' => 'application/json',
        ])->timeout(60);
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('services.marketing.api_url'), '/').$path;
    }
}
