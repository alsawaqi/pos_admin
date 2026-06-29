<script setup lang="ts">
/**
 * Marketing → Review → one advertiser. Shows a single advertiser's submitted
 * content (Pending / Reviewed) so the admin can approve (eligible for sliders)
 * or reject with a note. Reached from the Content Review landing list.
 */

import { ArrowLeft, CheckCircle2, Image as ImageIcon, Maximize2, Megaphone, Video } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import MediaLightbox from '@/Components/MediaLightbox.vue';
import { ApiError } from '@/lib/api';
import {
    approveContent,
    listReviewContent,
    rejectContent,
    type ReviewContentItem,
} from '@/lib/api/marketingContent';
import { usePermissions } from '@/composables/usePermissions';
import { PlatformPermission } from '@/lib/permissions';

const { can } = usePermissions();
const canReview = computed(() => can(PlatformPermission.MarketingContentReview));

const route = useRoute();
const advertiserId = Number(route.params.advertiserId);

const items = ref<ReviewContentItem[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const flash = ref<{ type: 'success' | 'error'; text: string } | null>(null);
const tab = ref<'pending' | 'reviewed'>('pending');
const selectedId = ref<number | null>(null);
const rejectNote = ref('');
const acting = ref(false);
const lightboxOpen = ref(false);

// Header brand name: from the navigation query, else from any loaded item.
const brandName = ref<string>((route.query.brand as string) || '');

const selected = computed(() => items.value.find((i) => i.id === selectedId.value) ?? items.value[0] ?? null);

const statusClass: Record<string, string> = {
    pending: 'bg-amber-100 text-amber-700',
    approved: 'bg-emerald-100 text-emerald-700',
    live: 'bg-teal-100 text-teal-700',
    expired: 'bg-slate-200 text-slate-600',
    rejected: 'bg-rose-100 text-rose-700',
    draft: 'bg-slate-200 text-slate-600',
};

function fmtAgo(s: string | null): string {
    if (!s) return '';
    const d = new Date(s).getTime();
    if (Number.isNaN(d)) return '';
    const mins = Math.max(1, Math.round((Date.now() - d) / 60000));
    if (mins < 60) return `${mins} min ago`;
    const hrs = Math.round(mins / 60);
    if (hrs < 24) return `${hrs} hour${hrs > 1 ? 's' : ''} ago`;
    return `${Math.round(hrs / 24)} day(s) ago`;
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const res = await listReviewContent({ view: tab.value, advertiser_id: advertiserId });
        items.value = res.data;
        if (!brandName.value && items.value[0]?.advertiser) {
            brandName.value = items.value[0].advertiser.brand_name;
        }
        if (!items.value.some((i) => i.id === selectedId.value)) {
            selectedId.value = items.value[0]?.id ?? null;
        }
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load content';
    } finally {
        loading.value = false;
    }
}

watch(tab, () => {
    selectedId.value = null;
    rejectNote.value = '';
    void load();
});

onMounted(() => void load());

function select(item: ReviewContentItem): void {
    selectedId.value = item.id;
    rejectNote.value = '';
}

async function approve(item: ReviewContentItem): Promise<void> {
    acting.value = true;
    flash.value = null;
    try {
        await approveContent(item.id);
        flash.value = { type: 'success', text: `Approved "${item.title}".` };
        await load();
    } catch (err) {
        flash.value = { type: 'error', text: messageOf(err) };
    } finally {
        acting.value = false;
    }
}

async function reject(item: ReviewContentItem): Promise<void> {
    acting.value = true;
    flash.value = null;
    try {
        await rejectContent(item.id, rejectNote.value.trim() || null);
        flash.value = { type: 'success', text: `Rejected "${item.title}".` };
        rejectNote.value = '';
        await load();
    } catch (err) {
        flash.value = { type: 'error', text: messageOf(err) };
    } finally {
        acting.value = false;
    }
}

function messageOf(err: unknown): string {
    if (err instanceof ApiError) {
        const msg = (err.payload as { message?: unknown } | null)?.message;
        if (typeof msg === 'string' && msg) return msg;
    }
    return err instanceof Error ? err.message : 'Action failed';
}
</script>

<template>
    <AdminLayout>
        <section class="space-y-6">
            <!-- Header -->
            <div>
                <RouterLink to="/admin/marketing/content" class="inline-flex items-center gap-1.5 text-sm font-semibold text-teal-700 hover:text-teal-800">
                    <ArrowLeft class="size-4" />
                    Content Review
                </RouterLink>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">{{ brandName || `Advertiser #${advertiserId}` }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                    Approve this advertiser's content so it can go into a slider, or reject it with a note they'll see.
                </p>
            </div>

            <div
                v-if="flash"
                class="rounded-lg border px-4 py-3 text-sm font-semibold"
                :class="flash.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-700'"
            >
                {{ flash.text }}
            </div>

            <!-- Tabs -->
            <div class="inline-flex rounded-lg border border-slate-200 bg-white p-1 shadow-sm">
                <button
                    type="button"
                    class="rounded-md px-5 py-2 text-sm font-semibold transition"
                    :class="tab === 'pending' ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-50'"
                    @click="tab = 'pending'"
                >Pending</button>
                <button
                    type="button"
                    class="rounded-md px-5 py-2 text-sm font-semibold transition"
                    :class="tab === 'reviewed' ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-50'"
                    @click="tab = 'reviewed'"
                >Reviewed</button>
            </div>

            <div v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">{{ error }}</div>

            <div class="grid gap-5 lg:grid-cols-[340px_1fr]">
                <!-- List -->
                <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                    <div v-if="loading" class="p-8 text-center text-sm text-slate-500">Loading…</div>
                    <div v-else-if="items.length === 0" class="flex flex-col items-center gap-2 p-10 text-center text-slate-500">
                        <Megaphone class="size-8 text-slate-300" />
                        <p class="text-sm font-semibold">Nothing here.</p>
                    </div>
                    <div v-else class="flex max-h-[64vh] flex-col gap-2 overflow-auto">
                        <button
                            v-for="item in items"
                            :key="item.id"
                            type="button"
                            class="flex items-center gap-3 rounded-xl border p-2.5 text-left transition"
                            :class="selected?.id === item.id ? 'border-teal-500 ring-2 ring-teal-100' : 'border-slate-200 hover:bg-slate-50'"
                            @click="select(item)"
                        >
                            <div class="grid size-12 shrink-0 place-items-center overflow-hidden rounded-lg bg-slate-100 text-slate-400">
                                <img v-if="item.type === 'image' && item.url" :src="item.url" :alt="item.title" class="size-full object-cover">
                                <img v-else-if="item.thumbnail_url" :src="item.thumbnail_url" :alt="item.title" class="size-full object-cover">
                                <Video v-else-if="item.type === 'video'" class="size-5" />
                                <ImageIcon v-else class="size-5" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-slate-950">{{ item.title }}</p>
                                <p class="truncate text-xs text-slate-500">{{ fmtAgo(item.submitted_at ?? item.created_at) }}</p>
                            </div>
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider" :class="statusClass[item.status]">{{ item.status }}</span>
                        </button>
                    </div>
                </div>

                <!-- Detail -->
                <div v-if="selected" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="group relative overflow-hidden rounded-xl bg-slate-900">
                        <img v-if="selected.type === 'image'" :src="selected.url ?? selected.thumbnail_url ?? ''" :alt="selected.title" class="max-h-[360px] w-full cursor-zoom-in object-contain" @click="lightboxOpen = true">
                        <video v-else controls :poster="selected.thumbnail_url ?? undefined" class="max-h-[360px] w-full">
                            <source :src="selected.url ?? ''">
                        </video>
                        <button
                            type="button"
                            class="absolute right-3 top-3 inline-flex items-center gap-1.5 rounded-lg bg-slate-950/60 px-3 py-1.5 text-xs font-semibold text-white opacity-0 transition hover:bg-slate-950/80 group-hover:opacity-100"
                            @click="lightboxOpen = true"
                        >
                            <Maximize2 class="size-3.5" /> View full
                        </button>
                    </div>

                    <div class="mt-4 flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-950">{{ selected.title }}</h2>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ selected.type === 'video' ? 'Video' : 'Image' }} · by {{ selected.advertiser?.brand_name ?? brandName ?? '—' }}
                            </p>
                        </div>
                        <span class="rounded-full px-3 py-1 text-xs font-bold uppercase tracking-wider" :class="statusClass[selected.status]">{{ selected.status }}</span>
                    </div>

                    <!-- Reviewed: show the note -->
                    <div v-if="selected.status !== 'pending'" class="mt-4">
                        <div v-if="selected.review_note" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                            <span class="font-semibold">Note to advertiser:</span> {{ selected.review_note }}
                        </div>
                        <p v-else class="text-sm text-slate-500">
                            <CheckCircle2 class="-mt-0.5 mr-1 inline size-4 text-emerald-500" />Reviewed.
                        </p>
                    </div>

                    <!-- Pending: approve / reject -->
                    <div v-else-if="canReview" class="mt-5 space-y-3 border-t border-slate-100 pt-5">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">Rejection note (optional)</span>
                            <textarea v-model="rejectNote" rows="2" placeholder="What needs to change?" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" />
                        </label>
                        <div class="flex items-center gap-3">
                            <button
                                type="button"
                                :disabled="acting"
                                class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700 disabled:opacity-60"
                                @click="approve(selected)"
                            >Approve</button>
                            <button
                                type="button"
                                :disabled="acting"
                                class="inline-flex items-center gap-2 rounded-lg border border-rose-200 bg-white px-5 py-2.5 text-sm font-semibold text-rose-700 transition hover:bg-rose-50 disabled:opacity-60"
                                @click="reject(selected)"
                            >Reject</button>
                        </div>
                    </div>
                </div>

                <div v-else class="grid place-items-center rounded-2xl border border-slate-200 bg-white p-12 text-center text-slate-500 shadow-sm">
                    <div>
                        <Megaphone class="mx-auto size-10 text-slate-300" />
                        <p class="mt-3 text-sm font-semibold">Select an item to review.</p>
                    </div>
                </div>
            </div>
        </section>

        <MediaLightbox
            v-if="lightboxOpen && selected"
            :type="selected.type"
            :url="selected.url"
            :poster="selected.thumbnail_url"
            :title="selected.title"
            @close="lightboxOpen = false"
        />
    </AdminLayout>
</template>
