<script setup lang="ts">
/**
 * Admin platform Round-Up Donations report — per-merchant charity
 * round-up totals over a date window, plus platform-wide headline counts
 * (total raised, donations, pending, failed, merchant count). reports.view
 * gated (the sidebar hides the entry otherwise; the server enforces the
 * same).
 *
 * Mirrors the admin Settlements report (Settlements/Index.vue):
 * trailing-30-day from/to filter, a single fetch on Run, headline tiles, a
 * ReportChart of the top merchants, and a per-merchant table. Money fields
 * arrive as decimal-3 OMR strings.
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import { HandCoins, Search } from 'lucide-vue-next';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import ReportChart from '@/Components/Admin/ReportChart.vue';
import { getRoundUpReport, type AdminRoundUpReport } from '@/lib/api/roundupReport';

const { t, locale } = useI18n();

function isoOffsetDays(offsetDays: number): string {
    const d = new Date();
    d.setDate(d.getDate() - offsetDays);
    return d.toISOString().slice(0, 10);
}

const report = ref<AdminRoundUpReport | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);

// Default to the trailing 30 days (mirrors the Settlements report defaults).
const fromDate = ref(isoOffsetDays(29));
const toDate = ref(isoOffsetDays(0));

async function fetchReport(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const response = await getRoundUpReport({
            from: fromDate.value || undefined,
            to: toDate.value || undefined,
        });
        report.value = response.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : t('roundup_report.load_failed');
    } finally {
        loading.value = false;
    }
}

function applyFilters(): void {
    void fetchReport();
}

onMounted(() => {
    void fetchReport();
});

/** Report money values arrive as decimal-3 strings — parse to a number. */
function num(v: string | number | undefined | null): number {
    const n = typeof v === 'number' ? v : Number.parseFloat(String(v ?? '0'));
    return Number.isFinite(n) ? n : 0;
}

/**
 * Pretty-prints a count with the locale's grouping separator. Falls back
 * to a plain string when Intl isn't available (defensive only).
 */
function formatCount(n: number): string {
    try {
        return new Intl.NumberFormat(locale.value === 'ar' ? 'ar-OM' : 'en-GB').format(n);
    } catch {
        return String(n);
    }
}

type ApexSeries = { name: string; data: number[] }[];

// Top merchants by round-up raised — capped at 10 bars to keep the chart
// readable (rows arrive already sorted by total_raised desc).
const topMerchantsChart = computed(() => {
    const rows = (report.value?.by_merchant ?? []).slice(0, 10);
    return {
        categories: rows.map((r) => r.company_name),
        series: [{ name: t('roundup_report.tiles.total_raised'), data: rows.map((r) => num(r.total_raised)) }] as ApexSeries,
    };
});
</script>

<template>
    <AdminLayout>
        <div class="max-w-7xl">
            <header class="mb-5 flex flex-col gap-1.5">
                <span class="text-xs font-semibold uppercase tracking-[0.15em] text-indigo-600">{{ t('roundup_report.section_label') }}</span>
                <h1 class="text-3xl font-bold text-slate-950">{{ t('roundup_report.title') }}</h1>
                <p class="max-w-3xl text-sm text-slate-600">{{ t('roundup_report.subtitle') }}</p>
            </header>

            <div class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
                    <span class="text-slate-500">{{ t('roundup_report.filters.date_from') }}</span>
                    <input type="date" v-model="fromDate" class="w-40 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" />
                </label>
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
                    <span class="text-slate-500">{{ t('roundup_report.filters.date_to') }}</span>
                    <input type="date" v-model="toDate" class="w-40 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" />
                </label>
                <button
                    type="button"
                    class="ms-auto inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:opacity-50"
                    :disabled="loading"
                    @click="applyFilters"
                >
                    <Search class="size-4" />
                    {{ loading ? t('roundup_report.filters.running') : t('roundup_report.filters.run') }}
                </button>
            </div>

            <div v-if="error" class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">{{ error }}</div>

            <template v-if="report">
                <!-- Headline tiles. Total raised is the prominent one
                     (indigo) — it's the charity money raised. -->
                <div class="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-4 shadow-sm sm:col-span-2 xl:col-span-1 xl:row-span-1">
                        <p class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-indigo-700">
                            <HandCoins class="size-3.5" />
                            {{ t('roundup_report.tiles.total_raised') }}
                        </p>
                        <p class="mt-1 text-2xl font-bold tabular-nums text-indigo-900">
                            {{ report.headline.total_raised }} <span class="text-sm font-medium">{{ t('roundup_report.currency') }}</span>
                        </p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('roundup_report.tiles.donations') }}</p>
                        <p class="mt-1 text-2xl font-bold tabular-nums text-slate-950">{{ formatCount(report.headline.donation_count) }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('roundup_report.tiles.pending') }}</p>
                        <p class="mt-1 text-2xl font-bold tabular-nums text-slate-950">{{ formatCount(report.headline.pending_count) }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('roundup_report.tiles.failed') }}</p>
                        <p class="mt-1 text-2xl font-bold tabular-nums text-slate-950">{{ formatCount(report.headline.failed_count) }}</p>
                    </div>
                </div>

                <!-- Secondary headline count. -->
                <div class="mb-6 flex flex-wrap gap-x-6 gap-y-1 text-sm text-slate-600">
                    <span>{{ t('roundup_report.tiles.num_merchants') }}: <strong class="tabular-nums text-slate-900">{{ formatCount(report.headline.num_merchants) }}</strong></span>
                </div>

                <!-- Top merchants by round-up raised. -->
                <ReportChart
                    v-if="topMerchantsChart.categories.length"
                    type="bar"
                    class="mb-6"
                    :title="t('roundup_report.top_merchants')"
                    :series="topMerchantsChart.series"
                    :categories="topMerchantsChart.categories"
                    :height="Math.max(220, topMerchantsChart.categories.length * 44)"
                    currency
                    horizontal
                    distributed
                    hide-legend
                    :empty-text="t('roundup_report.no_rows')"
                />

                <!-- Per-merchant breakdown table. -->
                <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                    <table v-if="report.by_merchant.length" class="w-full text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-2 text-start">{{ t('roundup_report.columns.merchant') }}</th>
                                <th class="px-5 py-2 text-end">{{ t('roundup_report.columns.total_raised') }}</th>
                                <th class="px-5 py-2 text-end">{{ t('roundup_report.columns.donations') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in report.by_merchant" :key="row.company_uuid" class="border-b border-slate-100 last:border-0">
                                <td class="px-5 py-2 font-medium">
                                    <RouterLink
                                        :to="`/admin/merchants/${row.company_uuid}`"
                                        class="text-slate-900 hover:text-teal-700"
                                    >
                                        {{ row.company_name }}
                                    </RouterLink>
                                </td>
                                <td class="px-5 py-2 text-end font-semibold tabular-nums text-indigo-900">{{ row.total_raised }}</td>
                                <td class="px-5 py-2 text-end tabular-nums text-slate-600">{{ formatCount(row.donation_count) }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-else class="p-8 text-center text-sm text-slate-500">{{ t('roundup_report.no_rows') }}</div>
                </div>

                <!-- Per-branch breakdown: which merchant branch (name + geo)
                     raised the round-up money. -->
                <h2 class="mb-3 mt-8 text-lg font-semibold text-slate-950">{{ t('roundup_report.by_branch') }}</h2>
                <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                    <table v-if="report.by_branch.length" class="w-full text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-2 text-start">{{ t('roundup_report.columns.branch') }}</th>
                                <th class="px-5 py-2 text-start">{{ t('roundup_report.columns.location') }}</th>
                                <th class="px-5 py-2 text-end">{{ t('roundup_report.columns.total_raised') }}</th>
                                <th class="px-5 py-2 text-end">{{ t('roundup_report.columns.donations') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in report.by_branch" :key="row.branch_id" class="border-b border-slate-100 last:border-0">
                                <td class="px-5 py-2 font-medium text-slate-900">{{ row.branch_name || t('roundup_report.unknown_branch') }}</td>
                                <td class="px-5 py-2 text-slate-600">{{ [row.city, row.region, row.country].filter(Boolean).join(', ') || '—' }}</td>
                                <td class="px-5 py-2 text-end font-semibold tabular-nums text-indigo-900">{{ row.total_raised }}</td>
                                <td class="px-5 py-2 text-end tabular-nums text-slate-600">{{ formatCount(row.donation_count) }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-else class="p-8 text-center text-sm text-slate-500">{{ t('roundup_report.no_rows') }}</div>
                </div>

                <!-- Individual donations, each traced to its ORDER (receipt) and
                     the payment LEG it rode — in a split, the exact guest's card
                     leg. This is the audit trail from charity money back to the sale. -->
                <h2 class="mb-3 mt-8 text-lg font-semibold text-slate-950">{{ t('roundup_report.recent_title') }}</h2>
                <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                    <table v-if="report.recent.length" class="w-full text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-2 text-start">{{ t('roundup_report.columns.time') }}</th>
                                <th class="px-5 py-2 text-start">{{ t('roundup_report.columns.order') }}</th>
                                <th class="px-5 py-2 text-start">{{ t('roundup_report.columns.merchant') }}</th>
                                <th class="px-5 py-2 text-start">{{ t('roundup_report.columns.branch') }}</th>
                                <th class="px-5 py-2 text-start">{{ t('roundup_report.columns.rode_on') }}</th>
                                <th class="px-5 py-2 text-end">{{ t('roundup_report.columns.amount') }}</th>
                                <th class="px-5 py-2 text-center">{{ t('roundup_report.columns.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="d in report.recent" :key="d.id" class="border-b border-slate-100 last:border-0">
                                <td class="px-5 py-2 text-xs tabular-nums text-slate-600">{{ d.occurred_at ? new Date(d.occurred_at).toLocaleString() : '—' }}</td>
                                <td class="px-5 py-2 font-mono text-xs font-semibold text-slate-900">{{ d.receipt_number ?? (d.order_uuid ? d.order_uuid.slice(0, 8).toUpperCase() : '—') }}</td>
                                <td class="px-5 py-2 text-slate-700">{{ d.company_name ?? '—' }}</td>
                                <td class="px-5 py-2 text-slate-600">{{ d.branch_name ?? '—' }}</td>
                                <td class="px-5 py-2 text-xs text-slate-600">
                                    <template v-if="d.payment_method">{{ d.payment_method }} {{ d.payment_amount ?? '' }}</template>
                                    <template v-else>—</template>
                                </td>
                                <td class="px-5 py-2 text-end font-semibold tabular-nums text-indigo-900">{{ d.amount }}</td>
                                <td class="px-5 py-2 text-center">
                                    <span
                                        class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                        :class="d.status === 'success' ? (d.forwarded ? 'bg-emerald-100 text-emerald-700' : 'bg-teal-50 text-teal-700') : d.status === 'pending' ? 'bg-amber-100 text-amber-700' : 'bg-rose-100 text-rose-700'"
                                    >{{ d.status === 'success' && d.forwarded ? t('roundup_report.forwarded') : t(`roundup_report.statuses.${d.status}`, d.status) }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-else class="p-8 text-center text-sm text-slate-500">{{ t('roundup_report.no_rows') }}</div>
                </div>
            </template>

            <div v-else-if="loading" class="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">{{ t('roundup_report.filters.running') }}</div>
        </div>
    </AdminLayout>
</template>
