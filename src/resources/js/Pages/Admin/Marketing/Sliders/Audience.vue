<script setup lang="ts">
/**
 * Marketing → Slider → Audience. Anonymous, AGGREGATE audience analytics for a
 * single slider, from device telemetry (play-time + on-device camera face
 * counts). No images or identities are ever stored — counts only. The numbers
 * populate once devices with audience measurement enabled report.
 */
import { ArrowLeft, Clock, Eye, MonitorPlay, Users } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import ReportChart from '@/Components/Admin/ReportChart.vue';
import { getSliderAudience, type SliderAudience } from '@/lib/api/marketingSliders';

const route = useRoute();
const router = useRouter();
const uuid = route.params.uuid as string;

const data = ref<SliderAudience | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        data.value = (await getSliderAudience(uuid)).data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load audience analytics';
    } finally {
        loading.value = false;
    }
}
onMounted(() => void load());

function fmtInt(n: number): string {
    return new Intl.NumberFormat('en-GB').format(n);
}
function fmtDuration(seconds: number): string {
    if (seconds <= 0) return '0s';
    if (seconds < 60) return `${seconds}s`;
    const m = Math.floor(seconds / 60);
    if (m < 60) return `${m}m`;
    const h = Math.floor(m / 60);
    return `${h}h ${m % 60}m`;
}

const summary = computed(() => data.value?.summary ?? null);
const hasData = computed(() => (summary.value?.plays ?? 0) > 0);

const timelineSeries = computed(() => [
    { name: 'Viewers', data: (data.value?.timeline ?? []).map((t) => t.viewers) },
    { name: 'Plays', data: (data.value?.timeline ?? []).map((t) => t.plays) },
]);
const timelineCategories = computed(() =>
    (data.value?.timeline ?? []).map((t) => {
        const d = new Date(t.date);
        return Number.isNaN(d.getTime())
            ? t.date
            : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    }),
);

function back(): void {
    void router.push({ name: 'admin.marketing.sliders.index' });
}
</script>

<template>
    <AdminLayout>
        <section class="space-y-6">
            <div>
                <button type="button" class="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-slate-800" @click="back">
                    <ArrowLeft class="size-4" /> Sliders
                </button>
                <p class="mt-3 text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">Audience</p>
                <h1 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">
                    {{ data?.slider.name ?? 'Slider' }}
                </h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                    Anonymous, on-device audience measurement — aggregate counts only, no images or identities.
                </p>
            </div>

            <div v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">{{ error }}</div>
            <div v-else-if="loading" class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm font-medium text-slate-500">Loading…</div>

            <template v-else-if="summary">
                <div
                    v-if="!hasData"
                    class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800"
                >
                    No plays recorded yet. Numbers appear once a device showing this slider reports — with audience measurement enabled, the camera counts viewers per ad.
                </div>

                <!-- Headline stats -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-center gap-2 text-slate-400"><Users class="size-4" /><span class="text-xs font-semibold uppercase tracking-wide">Distinct viewers</span></div>
                        <p class="mt-2 text-3xl font-bold text-slate-950">{{ fmtInt(summary.viewers_distinct) }}</p>
                        <p class="mt-1 text-xs text-slate-500">people who looked (OTS)</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-center gap-2 text-slate-400"><Eye class="size-4" /><span class="text-xs font-semibold uppercase tracking-wide">Peak concurrent</span></div>
                        <p class="mt-2 text-3xl font-bold text-slate-950">{{ fmtInt(summary.viewers_peak) }}</p>
                        <p class="mt-1 text-xs text-slate-500">most faces at once</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-center gap-2 text-slate-400"><Clock class="size-4" /><span class="text-xs font-semibold uppercase tracking-wide">Attention</span></div>
                        <p class="mt-2 text-3xl font-bold text-slate-950">{{ fmtDuration(summary.attention_seconds) }}</p>
                        <p class="mt-1 text-xs text-slate-500">time faces watched</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-center gap-2 text-slate-400"><MonitorPlay class="size-4" /><span class="text-xs font-semibold uppercase tracking-wide">Plays</span></div>
                        <p class="mt-2 text-3xl font-bold text-slate-950">{{ fmtInt(summary.plays) }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ fmtDuration(summary.play_seconds) }} of screen time</p>
                    </div>
                </div>

                <!-- Timeline -->
                <ReportChart
                    type="area"
                    title="Viewers over time"
                    subtitle="Last 30 days"
                    :series="timelineSeries"
                    :categories="timelineCategories"
                    :height="300"
                    empty-text="No plays in the last 30 days"
                />

                <!-- By branch -->
                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-200 px-5 py-3">
                        <h2 class="text-sm font-semibold text-slate-700">By branch</h2>
                    </div>
                    <div v-if="data && data.by_branch.length === 0" class="p-8 text-center text-sm text-slate-400">No branch data yet.</div>
                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">Branch</th>
                                    <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">Viewers</th>
                                    <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">Attention</th>
                                    <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">Plays</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                <tr v-for="b in data?.by_branch ?? []" :key="b.branch_id ?? 'none'" class="hover:bg-slate-50">
                                    <td class="px-5 py-4 text-sm font-semibold text-slate-950">{{ b.branch_name }}</td>
                                    <td class="px-5 py-4 text-end text-sm text-slate-700">{{ fmtInt(b.viewers) }}</td>
                                    <td class="px-5 py-4 text-end text-sm text-slate-700">{{ fmtDuration(b.attention_seconds) }}</td>
                                    <td class="px-5 py-4 text-end text-sm text-slate-700">{{ fmtInt(b.plays) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </template>
        </section>
    </AdminLayout>
</template>
