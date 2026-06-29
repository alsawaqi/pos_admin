<script setup lang="ts">
/**
 * Marketing → Review (landing) — the advertisers who have submitted content,
 * grouped so the admin tackles one advertiser at a time. Click an advertiser to
 * open just their submissions (Review/Show.vue) and approve / reject there.
 */

import { ChevronRight, Megaphone, Search } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { listContentSubmitters, type ContentSubmitter } from '@/lib/api/marketingContent';

const router = useRouter();

const submitters = ref<ContentSubmitter[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const search = ref('');

const totalPending = computed(() => submitters.value.reduce((sum, s) => sum + s.pending_count, 0));

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return submitters.value;
    return submitters.value.filter(
        (s) => s.brand_name.toLowerCase().includes(q) || s.name.toLowerCase().includes(q),
    );
});

function fmtAgo(s: string | null): string {
    if (!s) return '—';
    const d = new Date(s).getTime();
    if (Number.isNaN(d)) return '—';
    const mins = Math.max(1, Math.round((Date.now() - d) / 60000));
    if (mins < 60) return `${mins} min ago`;
    const hrs = Math.round(mins / 60);
    if (hrs < 24) return `${hrs}h ago`;
    return `${Math.round(hrs / 24)}d ago`;
}

function initials(s: ContentSubmitter): string {
    const n = s.brand_name || s.name || '';
    return n.split(' ').filter(Boolean).slice(0, 2).map((w) => w[0]!.toUpperCase()).join('') || '—';
}

function openAdvertiser(s: ContentSubmitter): void {
    void router.push({
        path: `/admin/marketing/content/${s.advertiser_id}`,
        query: { brand: s.brand_name },
    });
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const res = await listContentSubmitters();
        submitters.value = res.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load submissions';
    } finally {
        loading.value = false;
    }
}

onMounted(() => void load());
</script>

<template>
    <AdminLayout>
        <section class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">Marketing</p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">Content Review</h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                        Advertisers who have submitted content. Pick one to review their images and videos.
                        <span v-if="totalPending > 0" class="font-semibold text-amber-700">{{ totalPending }} pending in total.</span>
                    </p>
                </div>
            </div>

            <!-- Search -->
            <label class="flex max-w-md items-center gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3 text-slate-500 shadow-sm">
                <Search class="size-5 shrink-0" />
                <input
                    v-model="search"
                    type="search"
                    class="w-full bg-transparent text-sm font-medium text-slate-950 outline-none placeholder:text-slate-400"
                    placeholder="Search advertiser"
                >
            </label>

            <div v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">{{ error }}</div>

            <div v-if="loading" class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500 shadow-sm">Loading…</div>

            <div v-else-if="filtered.length === 0" class="flex flex-col items-center gap-3 rounded-2xl border border-slate-200 bg-white p-12 text-center text-slate-500 shadow-sm">
                <Megaphone class="size-10 text-slate-300" />
                <p class="text-sm font-semibold">No submissions yet.</p>
            </div>

            <div v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <button
                    v-for="s in filtered"
                    :key="s.advertiser_id"
                    type="button"
                    class="group flex items-center gap-4 rounded-2xl border border-slate-200 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:border-teal-300 hover:shadow-md"
                    @click="openAdvertiser(s)"
                >
                    <div class="grid size-12 shrink-0 place-items-center rounded-xl bg-teal-50 text-sm font-bold text-teal-700">{{ initials(s) }}</div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-slate-950">{{ s.brand_name }}</p>
                        <p class="truncate text-xs text-slate-500">{{ s.name }} · last {{ fmtAgo(s.last_submitted_at) }}</p>
                        <div class="mt-2 flex items-center gap-2">
                            <span
                                v-if="s.pending_count > 0"
                                class="rounded-full bg-amber-100 px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wide text-amber-700"
                            >{{ s.pending_count }} pending</span>
                            <span v-else class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wide text-emerald-700">All reviewed</span>
                            <span class="text-[11px] font-medium text-slate-400">{{ s.total }} total</span>
                        </div>
                    </div>
                    <ChevronRight class="size-5 shrink-0 text-slate-300 transition group-hover:text-teal-500" />
                </button>
            </div>
        </section>
    </AdminLayout>
</template>
