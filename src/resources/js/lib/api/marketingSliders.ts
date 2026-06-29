/**
 * TypeScript client for the slider builder (Marketing → Sliders). The admin
 * groups approved advertiser content into an ordered loop and targets it at
 * branches/devices. pos_admin owns the slider tables; pos_api reads them for
 * /device/config. Gated by marketing.sliders.manage.
 */

import { ApiError, apiDelete, apiGet, apiPatch, apiPost, type JsonValue } from '@/lib/api';

export type SliderStatus = 'draft' | 'active' | 'paused';
export type ContentType = 'image' | 'video';

export interface SliderItemContent {
    id: number;
    title: string;
    type: ContentType;
    status: string;
    url: string | null;
    thumbnail_url: string | null;
    duration_seconds: number | null;
}

export interface SliderItem {
    id: number;
    content_asset_id: number;
    advertiser_id: number | null;
    sort_order: number;
    duration_seconds: number;
    content?: SliderItemContent | null;
    advertiser?: { id: number; brand_name: string } | null;
}

export interface SliderTarget {
    id: number;
    branch_id: number | null;
    device_id: number | null;
    branch?: { id: number; uuid: string; name: string } | null;
    device?: { id: number; uuid: string; name: string | null } | null;
}

export interface Slider {
    id: number;
    uuid: string;
    name: string;
    loop_interval_seconds: number;
    status: SliderStatus;
    starts_at: string | null;
    ends_at: string | null;
    items_count?: number;
    targets_count?: number;
    items?: SliderItem[];
    targets?: SliderTarget[];
    created_at: string | null;
}

export interface SliderOptionsContent {
    id: number;
    title: string;
    type: ContentType;
    status: string;
    url: string | null;
    thumbnail_url: string | null;
    duration_seconds: number | null;
    advertiser_id: number | null;
    advertiser: { id: number; brand_name: string } | null;
}

export interface SliderOptionsBranch {
    id: number;
    uuid: string;
    name: string;
    company_id: number | null;
}

export interface SliderOptionsDevice {
    id: number;
    uuid: string;
    name: string | null;
    branch_id: number | null;
    branch_name: string | null;
    status: string | null;
    in_use: boolean;
}

export interface SliderOptions {
    content: SliderOptionsContent[];
    branches: SliderOptionsBranch[];
    devices: SliderOptionsDevice[];
}

export interface SliderItemInput {
    content_asset_id: number;
    duration_seconds?: number | null;
}

export interface SliderTargetInput {
    branch_id?: number | null;
    device_id?: number | null;
}

export interface SliderPayload {
    name: string;
    loop_interval_seconds?: number;
    status?: SliderStatus;
    starts_at?: string | null;
    ends_at?: string | null;
    items: SliderItemInput[];
    targets?: SliderTargetInput[];
}

export function listSliders(): Promise<{ data: Slider[] }> {
    return apiGet<{ data: Slider[] }>('/admin/api/v1/marketing/sliders');
}

export function getSliderOptions(): Promise<{ data: SliderOptions }> {
    return apiGet<{ data: SliderOptions }>('/admin/api/v1/marketing/sliders/options');
}

export function getSlider(uuid: string): Promise<{ data: Slider }> {
    return apiGet<{ data: Slider }>(`/admin/api/v1/marketing/sliders/${uuid}`);
}

export interface SliderAudienceSummary {
    plays: number;
    play_seconds: number;
    measured_plays: number;
    viewers_distinct: number;
    viewers_peak: number;
    viewers_avg: number;
    attention_seconds: number;
}

export interface SliderAudienceBranch {
    branch_id: number | null;
    branch_name: string;
    plays: number;
    viewers: number;
    attention_seconds: number;
}

export interface SliderAudience {
    slider: { uuid: string; name: string };
    summary: SliderAudienceSummary;
    by_branch: SliderAudienceBranch[];
    timeline: Array<{ date: string; plays: number; viewers: number }>;
}

/** Anonymous audience analytics for a slider (camera face counts + play-time). */
export function getSliderAudience(uuid: string): Promise<{ data: SliderAudience }> {
    return apiGet<{ data: SliderAudience }>(`/admin/api/v1/marketing/sliders/${uuid}/audience`);
}

export function createSlider(payload: SliderPayload): Promise<{ data: Slider }> {
    return apiPost<{ data: Slider }>('/admin/api/v1/marketing/sliders', payload as unknown as JsonValue);
}

export function updateSlider(uuid: string, payload: Partial<SliderPayload>): Promise<{ data: Slider }> {
    return apiPatch<{ data: Slider }>(`/admin/api/v1/marketing/sliders/${uuid}`, payload as unknown as JsonValue);
}

export function deleteSlider(uuid: string): Promise<void> {
    return apiDelete<void>(`/admin/api/v1/marketing/sliders/${uuid}`);
}

export interface SliderConflict {
    category: string;
    advertiser_brand: string;
    competitor_brand: string;
    merchant_name: string | null;
    branch_count: number;
}

/** Advisory competitor check — non-blocking warnings for the builder. */
export function checkSliderConflicts(
    advertiserIds: number[],
    branchIds: number[],
): Promise<{ data: { conflicts: SliderConflict[] } }> {
    return apiPost<{ data: { conflicts: SliderConflict[] } }>(
        '/admin/api/v1/marketing/sliders/check-conflicts',
        { advertiser_ids: advertiserIds, branch_ids: branchIds } as unknown as JsonValue,
    );
}

// --- Admin direct upload (media straight into a slider) ---------------------
// The file is forwarded to marketing-api's shared content store; the asset is
// platform-owned (no advertiser) + pre-approved. Multipart, so it bypasses the
// JSON apiPost helper (raw fetch with the CSRF token, like merchant docs).

export interface UploadedContentAsset {
    id: number;
    title: string;
    type: ContentType;
    status: string;
    url: string | null;
    thumbnail_url: string | null;
    duration_seconds: number | null;
}

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

async function postMultipart(url: string, form: FormData): Promise<UploadedContentAsset> {
    const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: form,
    });

    const json: unknown = await res.json().catch(() => null);
    if (!res.ok) {
        const message = (json as { message?: string } | null)?.message ?? 'Upload failed.';
        throw new ApiError(res.status, json, message);
    }
    return (json as { data: UploadedContentAsset }).data;
}

/** Admin upload — an image/video straight into a slider (platform-owned, approved). */
export function uploadSliderContent(file: File, title: string): Promise<UploadedContentAsset> {
    const form = new FormData();
    form.append('title', title);
    form.append('file', file);
    return postMultipart('/admin/api/v1/marketing/content/upload', form);
}

/** Filerobot editor save-back for an admin-owned image asset (new file). */
export function replaceSliderContent(assetId: number, file: File): Promise<UploadedContentAsset> {
    const form = new FormData();
    form.append('file', file);
    return postMultipart(`/admin/api/v1/marketing/content/${assetId}/replace`, form);
}
