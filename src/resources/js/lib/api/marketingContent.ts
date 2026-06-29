/**
 * TypeScript client for content review (Marketing → Review). Advertisers submit
 * content on the marketing portal; the admin approves it (eligible for sliders)
 * or rejects it with a note. content_assets is owned by the marketing-api app
 * (shared charity_db); pos_admin only writes the review fields.
 *
 * Endpoints (admin.php), gated by marketing.content.review:
 *   GET  /admin/api/v1/marketing/content?view=pending|reviewed
 *   POST /admin/api/v1/marketing/content/{id}/approve
 *   POST /admin/api/v1/marketing/content/{id}/reject   { note? }
 */

import { apiGet, apiPost, type JsonValue } from '@/lib/api';

export type ContentStatus = 'draft' | 'pending' | 'approved' | 'live' | 'expired' | 'rejected';
export type ContentType = 'image' | 'video';

export interface ReviewContentItem {
    id: number;
    title: string;
    type: ContentType;
    status: ContentStatus;
    advertiser_id: number | null;
    advertiser: { id: number; brand_name: string; name: string } | null;
    url: string | null;
    thumbnail_url: string | null;
    duration_seconds: number | null;
    width: number | null;
    height: number | null;
    review_note: string | null;
    submitted_at: string | null;
    reviewed_at: string | null;
    created_at: string | null;
}

export interface ContentReviewQuery {
    view?: 'pending' | 'reviewed';
    advertiser_id?: number;
    [key: string]: string | number | boolean | null | undefined;
}

/** One advertiser who has submitted content, with their pending count. */
export interface ContentSubmitter {
    advertiser_id: number;
    brand_name: string;
    name: string;
    status: string | null;
    pending_count: number;
    total: number;
    last_submitted_at: string | null;
}

/** The advertisers who have submitted content — the review landing list. */
export function listContentSubmitters(): Promise<{ data: ContentSubmitter[] }> {
    return apiGet<{ data: ContentSubmitter[] }>('/admin/api/v1/marketing/content/submitters');
}

export function listReviewContent(query: ContentReviewQuery = {}): Promise<{ data: ReviewContentItem[] }> {
    return apiGet<{ data: ReviewContentItem[] }>('/admin/api/v1/marketing/content', { query });
}

export function approveContent(id: number): Promise<{ data: ReviewContentItem }> {
    return apiPost<{ data: ReviewContentItem }>(`/admin/api/v1/marketing/content/${id}/approve`);
}

export function rejectContent(id: number, note?: string | null): Promise<{ data: ReviewContentItem }> {
    return apiPost<{ data: ReviewContentItem }>(
        `/admin/api/v1/marketing/content/${id}/reject`,
        { note: note ?? null } as unknown as JsonValue,
    );
}
