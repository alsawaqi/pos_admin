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
import { CheckCircle2, Loader2, Search, Wallet } from 'lucide-vue-next';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import ReportChart from '@/Components/Admin/ReportChart.vue';
import BaseModal from '@/Components/BaseModal.vue';
import ConfirmDialog from '@/Components/Admin/ConfirmDialog.vue';
import { ApiError } from '@/lib/api';
import { getSettlementReport, type AdminSettlementReport, type SettlementMerchantRow } from '@/lib/api/settlementReport';
import {
    cancelPayout,
    createPayout,
    listPayouts,
    markPayoutPaid,
    type PayoutRow,
    type PayoutStatus,
} from '@/lib/api/payouts';
import { usePermissions } from '@/composables/usePermissions';
import { PlatformPermission } from '@/lib/permissions';

const { t, locale } = useI18n();
const { can } = usePermissions();
const canManage = computed(() => can(PlatformPermission.SettingsManage));

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

// ── Payouts state ────────────────────────────────────────────────────────
const payouts = ref<PayoutRow[]>([]);
const payoutsLoading = ref(false);
const payoutsError = ref<string | null>(null);
// A single shared notice surfaces success / 422 outcomes from every payout
// action (create / mark-paid / cancel) without crashing the page.
const notice = ref<{ type: 'success' | 'error'; text: string } | null>(null);
// company_uuid of the merchant whose Create-payout request is in flight.
const creatingFor = ref<string | null>(null);

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

async function fetchPayouts(): Promise<void> {
    payoutsLoading.value = true;
    payoutsError.value = null;
    try {
        const response = await listPayouts();
        payouts.value = response.data;
    } catch (err) {
        payoutsError.value = err instanceof Error ? err.message : t('settlements.payouts.load_failed');
    } finally {
        payoutsLoading.value = false;
    }
}

function applyFilters(): void {
    void fetchReport();
    void fetchPayouts();
}

onMounted(() => {
    void fetchReport();
    void fetchPayouts();
});

/** Pull the human message off any error (422 {message} included). */
function messageOf(err: unknown, fallback: string): string {
    if (err instanceof ApiError) {
        return err.firstValidationMessage() ?? err.message;
    }
    return err instanceof Error ? err.message : fallback;
}

/** Create a payout for one merchant over the CURRENT from/to window. */
async function onCreatePayout(row: SettlementMerchantRow): Promise<void> {
    creatingFor.value = row.company_uuid;
    notice.value = null;
    try {
        await createPayout({
            companyUuid: row.company_uuid,
            from: fromDate.value,
            to: toDate.value,
        });
        notice.value = { type: 'success', text: t('settlements.payouts.created_notice') };
        await fetchPayouts();
    } catch (err) {
        // 422 ("nothing unsettled") + every other failure surface as a
        // non-fatal inline notice rather than throwing.
        notice.value = { type: 'error', text: messageOf(err, t('settlements.payouts.nothing_to_pay')) };
    } finally {
        creatingFor.value = null;
    }
}

// ── Mark-paid modal ──────────────────────────────────────────────────────
const markPaidTarget = ref<PayoutRow | null>(null);
const markPaidReference = ref('');
const markPaidNote = ref('');
const markPaidSaving = ref(false);
const markPaidError = ref<string | null>(null);

function openMarkPaid(row: PayoutRow): void {
    markPaidTarget.value = row;
    markPaidReference.value = '';
    markPaidNote.value = '';
    markPaidError.value = null;
}

function closeMarkPaid(): void {
    markPaidTarget.value = null;
}

async function confirmMarkPaid(): Promise<void> {
    if (markPaidTarget.value === null) {
        return;
    }
    markPaidSaving.value = true;
    markPaidError.value = null;
    try {
        await markPayoutPaid(markPaidTarget.value.uuid, {
            reference: markPaidReference.value.trim() || undefined,
            note: markPaidNote.value.trim() || undefined,
        });
        markPaidTarget.value = null;
        notice.value = { type: 'success', text: t('settlements.payouts.paid_notice') };
        await fetchPayouts();
    } catch (err) {
        // 422 (not pending) stays inside the modal.
        markPaidError.value = messageOf(err, t('settlements.payouts.load_failed'));
    } finally {
        markPaidSaving.value = false;
    }
}

// ── Cancel confirm ───────────────────────────────────────────────────────
const cancelTarget = ref<PayoutRow | null>(null);
const cancelSaving = ref(false);
const cancelError = ref<string | null>(null);

function openCancel(row: PayoutRow): void {
    cancelTarget.value = row;
    cancelError.value = null;
}

function closeCancel(): void {
    cancelTarget.value = null;
}

async function confirmCancel(): Promise<void> {
    if (cancelTarget.value === null) {
        return;
    }
    cancelSaving.value = true;
    cancelError.value = null;
    try {
        await cancelPayout(cancelTarget.value.uuid);
        cancelTarget.value = null;
        notice.value = { type: 'success', text: t('settlements.payouts.cancelled_notice') };
        await fetchPayouts();
    } catch (err) {
        // 422 (not pending) stays inside the confirm dialog.
        cancelError.value = messageOf(err, t('settlements.payouts.load_failed'));
    } finally {
        cancelSaving.value = false;
    }
}

/** Status badge palette. */
const STATUS_BADGE: Record<PayoutStatus, string> = {
    pending: 'bg-amber-50 text-amber-700 ring-amber-200',
    paid: 'bg-teal-50 text-teal-700 ring-teal-200',
    cancelled: 'bg-slate-100 text-slate-500 ring-slate-200',
};

/** ISO datetime → short local date (Latin numerals). */
function shortDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }
    return iso.slice(0, 10);
}

function periodLabel(row: PayoutRow): string {
    return `${shortDate(row.period_from)} → ${shortDate(row.period_to)}`;
}

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

            <!-- Shared notice for every payout action (create / mark-paid / cancel). -->
            <div
                v-if="notice"
                class="mb-4 flex items-start justify-between gap-3 rounded-lg border px-4 py-3 text-sm font-semibold"
                :class="notice.type === 'success' ? 'border-teal-200 bg-teal-50 text-teal-800' : 'border-rose-200 bg-rose-50 text-rose-800'"
            >
                <span>{{ notice.text }}</span>
                <button type="button" class="text-current opacity-60 transition hover:opacity-100" @click="notice = null">×</button>
            </div>

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
                                <th v-if="canManage" class="px-5 py-2 text-end">{{ t('settlements.actions_column') }}</th>
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
                                <td v-if="canManage" class="px-5 py-2 text-end">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-100 disabled:cursor-wait disabled:opacity-50"
                                        :disabled="creatingFor !== null"
                                        @click="onCreatePayout(row)"
                                    >
                                        <Loader2 v-if="creatingFor === row.company_uuid" class="size-3.5 animate-spin" />
                                        {{ creatingFor === row.company_uuid ? t('settlements.creating_payout') : t('settlements.create_payout') }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-else class="p-8 text-center text-sm text-slate-500">{{ t('settlements.no_rows') }}</div>
                </div>
            </template>

            <div v-else-if="loading" class="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">{{ t('settlements.filters.running') }}</div>

            <!-- ── Payouts section ──────────────────────────────────────── -->
            <section class="mt-8">
                <header class="mb-3">
                    <h2 class="text-xl font-bold text-slate-950">{{ t('settlements.payouts.section_title') }}</h2>
                    <p class="max-w-3xl text-sm text-slate-600">{{ t('settlements.payouts.section_subtitle') }}</p>
                </header>

                <div v-if="payoutsError" class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">{{ payoutsError }}</div>

                <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                    <table v-if="payouts.length" class="w-full text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-2 text-start">{{ t('settlements.payouts.columns.merchant') }}</th>
                                <th class="px-5 py-2 text-start">{{ t('settlements.payouts.columns.period') }}</th>
                                <th class="px-5 py-2 text-end">{{ t('settlements.payouts.columns.net_amount') }}</th>
                                <th class="px-5 py-2 text-center">{{ t('settlements.payouts.columns.status') }}</th>
                                <th class="px-5 py-2 text-end">{{ t('settlements.payouts.columns.num_sales') }}</th>
                                <th class="px-5 py-2 text-start">{{ t('settlements.payouts.columns.paid_at') }}</th>
                                <th class="px-5 py-2 text-start">{{ t('settlements.payouts.columns.reference') }}</th>
                                <th v-if="canManage" class="px-5 py-2 text-end">{{ t('settlements.payouts.columns.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in payouts" :key="p.uuid" class="border-b border-slate-100 last:border-0">
                                <td class="px-5 py-2 font-medium text-slate-900">{{ p.company_name ?? '—' }}</td>
                                <td class="px-5 py-2 whitespace-nowrap tabular-nums text-slate-600">{{ periodLabel(p) }}</td>
                                <td class="px-5 py-2 text-end font-semibold tabular-nums text-indigo-900">{{ p.net_amount }} <span class="text-xs font-normal text-slate-400">{{ t('settlements.currency') }}</span></td>
                                <td class="px-5 py-2 text-center">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset" :class="STATUS_BADGE[p.status]">
                                        {{ t(`settlements.payouts.statuses.${p.status}`) }}
                                    </span>
                                </td>
                                <td class="px-5 py-2 text-end tabular-nums text-slate-600">{{ formatCount(p.sales_count) }}</td>
                                <td class="px-5 py-2 whitespace-nowrap tabular-nums text-slate-500">{{ shortDate(p.paid_at) }}</td>
                                <td class="px-5 py-2 text-slate-600">{{ p.reference ?? '—' }}</td>
                                <td v-if="canManage" class="px-5 py-2 text-end">
                                    <div v-if="p.status === 'pending'" class="flex justify-end gap-2">
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-1.5 rounded-lg bg-teal-700 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-teal-800"
                                            @click="openMarkPaid(p)"
                                        >
                                            <CheckCircle2 class="size-3.5" />
                                            {{ t('settlements.payouts.mark_paid') }}
                                        </button>
                                        <button
                                            type="button"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-100"
                                            @click="openCancel(p)"
                                        >
                                            {{ t('settlements.payouts.cancel') }}
                                        </button>
                                    </div>
                                    <span v-else class="text-xs text-slate-400">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-else-if="payoutsLoading" class="p-8 text-center text-sm text-slate-500">{{ t('settlements.filters.running') }}</div>
                    <div v-else class="p-8 text-center text-sm text-slate-500">{{ t('settlements.payouts.no_rows') }}</div>
                </div>
            </section>
        </div>

        <!-- Mark-paid modal: optional reference + note. -->
        <BaseModal
            v-if="markPaidTarget"
            :title="t('settlements.payouts.mark_paid_title')"
            size="md"
            :loading="markPaidSaving"
            @close="closeMarkPaid"
        >
            <div class="space-y-4">
                <p class="text-sm text-slate-600">{{ markPaidTarget.company_name ?? '—' }} — <span class="font-semibold text-indigo-900">{{ markPaidTarget.net_amount }} {{ t('settlements.currency') }}</span></p>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('settlements.payouts.reference') }}</span>
                    <input
                        v-model="markPaidReference"
                        type="text"
                        maxlength="120"
                        :placeholder="t('settlements.payouts.reference_placeholder')"
                        class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                    >
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('settlements.payouts.note') }}</span>
                    <textarea
                        v-model="markPaidNote"
                        rows="3"
                        maxlength="1000"
                        :placeholder="t('settlements.payouts.note_placeholder')"
                        class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                    />
                </label>
                <p v-if="markPaidError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">{{ markPaidError }}</p>
            </div>

            <template #footer>
                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-wait disabled:opacity-50"
                        :disabled="markPaidSaving"
                        @click="closeMarkPaid"
                    >
                        {{ t('common.cancel') }}
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-800 disabled:cursor-wait disabled:opacity-60"
                        :disabled="markPaidSaving"
                        @click="confirmMarkPaid"
                    >
                        <Loader2 v-if="markPaidSaving" class="size-4 animate-spin" />
                        {{ markPaidSaving ? t('settlements.payouts.marking_paid') : t('settlements.payouts.confirm_mark_paid') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- Cancel confirm. -->
        <ConfirmDialog
            v-if="cancelTarget"
            :title="t('settlements.payouts.cancel_title')"
            :message="t('settlements.payouts.cancel_message')"
            :confirm-label="t('settlements.payouts.cancel_confirm')"
            :cancel-label="t('settlements.payouts.cancel_keep')"
            tone="danger"
            :loading="cancelSaving"
            :error="cancelError"
            @confirm="confirmCancel"
            @cancel="closeCancel"
        />
    </AdminLayout>
</template>
