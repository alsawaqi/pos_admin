<script setup lang="ts">
/**
 * Admin platform Settlements (v2 #17) — per-merchant commission
 * breakdown + platform totals over a date window. Shows how much the
 * platform owes each merchant (merchant net) alongside its own revenue,
 * the bank fees, and other deductions. reports.view gated (sidebar hides
 * the entry otherwise; the server enforces the same).
 *
 * Mirrors the admin Sales report consumption (Dashboard.vue / the
 * Merchants/Show "Sales" tab): trailing-30-day from/to filter, a single
 * fetch on Run, headline tiles, a ReportChart, and a per-merchant table.
 * Money fields arrive as decimal-3 OMR strings.
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import { Search, Wallet } from 'lucide-vue-next';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import ReportChart from '@/Components/Admin/ReportChart.vue';
import { getSettlementReport, type AdminSettlementReport } from '@/lib/api/settlementReport';

const { t, locale } = useI18n();

function isoOffsetDays(offsetDays: number): string {
    const d = new Date();
    d.setDate(d.getDate() - offsetDays);
    return d.toISOString().slice(0, 10);
}

const report = ref<AdminSettlementReport | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);

// Default to the trailing 30 days (mirrors the Sales report defaults).
const fromDate = ref(isoOffsetDays(29));
const toDate = ref(isoOffsetDays(0));

async function fetchReport(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const response = await getSettlementReport({
            from: fromDate.value || undefined,
            to: toDate.value || undefined,
        });
        report.value = response.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : t('settlements.load_failed');
    } finally {
        loading.value = false;
    }
}

function applyFilters(): void {
    void fetchReport();
}

onMounted(() => void fetchReport());

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

// Top merchants by net payable — capped at 10 bars to keep the chart
// readable (rows arrive already sorted by merchant_net desc).
const topMerchantsChart = computed(() => {
    const rows = (report.value?.by_merchant ?? []).slice(0, 10);
    return {
        categories: rows.map((r) => r.company_name),
        series: [{ name: t('settlements.tiles.merchant_payable'), data: rows.map((r) => num(r.merchant_net)) }] as ApexSeries,
    };
});
</script>

<template>
    <AdminLayout>
        <div class="max-w-7xl">
            <header class="mb-5 flex flex-col gap-1.5">
                <span class="text-xs font-semibold uppercase tracking-[0.15em] text-indigo-600">{{ t('settlements.section_label') }}</span>
                <h1 class="text-3xl font-bold text-slate-950">{{ t('settlements.title') }}</h1>
                <p class="max-w-3xl text-sm text-slate-600">{{ t('settlements.subtitle') }}</p>
            </header>

            <div class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
                    <span class="text-slate-500">{{ t('settlements.filters.date_from') }}</span>
                    <input type="date" v-model="fromDate" class="w-40 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" />
                </label>
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
                    <span class="text-slate-500">{{ t('settlements.filters.date_to') }}</span>
                    <input type="date" v-model="toDate" class="w-40 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" />
                </label>
                <button
                    type="button"
                    class="ms-auto inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:opacity-50"
                    :disabled="loading"
                    @click="applyFilters"
                >
                    <Search class="size-4" />
                    {{ loading ? t('settlements.filters.running') : t('settlements.filters.run') }}
                </button>
            </div>

            <div v-if="error" class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">{{ error }}</div>

            <template v-if="report">
                <!-- Headline tiles. Total merchant payable is the
                     prominent one (indigo) — it's the money owed out. -->
                <div class="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-4 shadow-sm sm:col-span-2 xl:col-span-1 xl:row-span-1">
                        <p class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-indigo-700">
                            <Wallet class="size-3.5" />
                            {{ t('settlements.tiles.merchant_payable') }}
                        </p>
                        <p class="mt-1 text-2xl font-bold tabular-nums text-indigo-900">
                            {{ report.headline.merchant_payable }} <span class="text-sm font-medium">{{ t('settlements.currency') }}</span>
                        </p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('settlements.tiles.gross') }}</p>
                        <p class="mt-1 text-2xl font-bold tabular-nums text-slate-950">
                            {{ report.headline.gross }} <span class="text-sm font-medium text-slate-400">{{ t('settlements.currency') }}</span>
                        </p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('settlements.tiles.platform_revenue') }}</p>
                        <p class="mt-1 text-2xl font-bold tabular-nums text-slate-950">
                            {{ report.headline.platform_revenue }} <span class="text-sm font-medium text-slate-400">{{ t('settlements.currency') }}</span>
                        </p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('settlements.tiles.bank_fees') }}</p>
                        <p class="mt-1 text-2xl font-bold tabular-nums text-slate-950">
                            {{ report.headline.bank_total }} <span class="text-sm font-medium text-slate-400">{{ t('settlements.currency') }}</span>
                        </p>
                    </div>
                </div>

                <!-- Secondary headline counts. -->
                <div class="mb-6 flex flex-wrap gap-x-6 gap-y-1 text-sm text-slate-600">
                    <span>{{ t('settlements.tiles.num_sales') }}: <strong class="tabular-nums text-slate-900">{{ formatCount(report.headline.num_sales) }}</strong></span>
                    <span>{{ t('settlements.tiles.num_merchants') }}: <strong class="tabular-nums text-slate-900">{{ formatCount(report.headline.num_merchants) }}</strong></span>
                </div>

                <!-- Top merchants by net payable. -->
                <ReportChart
                    v-if="topMerchantsChart.categories.length"
                    type="bar"
                    class="mb-6"
                    :title="t('settlements.top_merchants')"
                    :series="topMerchantsChart.series"
                    :categories="topMerchantsChart.categories"
                    :height="Math.max(220, topMerchantsChart.categories.length * 44)"
                    currency
                    horizontal
                    distributed
                    hide-legend
                    :empty-text="t('settlements.no_rows')"
                />

                <!-- Per-merchant breakdown table. -->
                <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                    <table v-if="report.by_merchant.length" class="w-full text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-2 text-start">{{ t('settlements.columns.merchant') }}</th>
                                <th class="px-5 py-2 text-end">{{ t('settlements.columns.gross') }}</th>
                                <th class="px-5 py-2 text-end">{{ t('settlements.columns.platform') }}</th>
                                <th class="px-5 py-2 text-end">{{ t('settlements.columns.bank') }}</th>
                                <th class="px-5 py-2 text-end">{{ t('settlements.columns.merchant_net') }}</th>
                                <th class="px-5 py-2 text-end">{{ t('settlements.columns.num_sales') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in report.by_merchant" :key="row.company_id" class="border-b border-slate-100 last:border-0">
                                <td class="px-5 py-2 font-medium">
                                    <RouterLink
                                        :to="`/admin/merchants/${row.company_uuid}`"
                                        class="text-slate-900 hover:text-teal-700"
                                    >
                                        {{ row.company_name }}
                                    </RouterLink>
                                </td>
                                <td class="px-5 py-2 text-end tabular-nums text-slate-700">{{ row.gross }}</td>
                                <td class="px-5 py-2 text-end tabular-nums text-slate-700">{{ row.platform }}</td>
                                <td class="px-5 py-2 text-end tabular-nums text-slate-700">{{ row.bank }}</td>
                                <td class="px-5 py-2 text-end font-semibold tabular-nums text-indigo-900">{{ row.merchant_net }}</td>
                                <td class="px-5 py-2 text-end tabular-nums text-slate-600">{{ formatCount(row.num_sales) }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-else class="p-8 text-center text-sm text-slate-500">{{ t('settlements.no_rows') }}</div>
                </div>
            </template>

            <div v-else-if="loading" class="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">{{ t('settlements.filters.running') }}</div>
        </div>
    </AdminLayout>
</template>
