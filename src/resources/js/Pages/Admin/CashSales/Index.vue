<script setup lang="ts">
/**
 * Cash & Bank POS — the merchant-holds-the-money side, fully SEPARATED from the
 * card flow. Only pure cash/bank-POS sales appear here: the merchant already
 * collected this money, so the platform's job is the reverse of the card page —
 * see what they took (cash vs bank-POS), verify each sale's commission, and BILL
 * it back via a commission invoice. Same drill as Sales: merchants → branches →
 * the per-terminal verification workspace (?pm=cash_bank). reports.view gated.
 */
import { Banknote, Building2, ChevronDown, ChevronRight, ListChecks, Search } from 'lucide-vue-next';
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { listSalesSummary, type SalesSummaryMerchant } from '@/lib/api/orders';

const { t } = useI18n();

function isoOffsetDays(offsetDays: number): string {
    const d = new Date();
    d.setDate(d.getDate() - offsetDays);
    return d.toISOString().slice(0, 10);
}

// Commission is typically billed per period — default to the current month.
const fromDate = ref(isoOffsetDays(30));
const toDate = ref(isoOffsetDays(0));

const summary = ref<SalesSummaryMerchant[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);
const expanded = ref<Set<string>>(new Set());

async function fetchSummary(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const res = await listSalesSummary(fromDate.value, toDate.value, 'cash_bank');
        summary.value = res.data;
    } catch {
        error.value = t('cash_sales.load_failed');
    } finally {
        loading.value = false;
    }
}

onMounted(fetchSummary);

function toggle(uuid: string): void {
    const next = new Set(expanded.value);
    next.has(uuid) ? next.delete(uuid) : next.add(uuid);
    expanded.value = next;
}

/** Open the branch's cash/bank-POS verification workspace. */
function verifyTo(m: SalesSummaryMerchant, branchUuid: string, branchName: string): string {
    const q = new URLSearchParams({
        company: m.company_uuid,
        company_name: m.company_name,
        branch: branchUuid,
        branch_name: branchName,
        from: fromDate.value,
        to: toDate.value,
        pm: 'cash_bank',
    });
    return `/admin/settlements/reconcile?${q.toString()}`;
}
</script>

<template>
    <AdminLayout>
        <div class="max-w-7xl">
            <header class="mb-5 flex flex-col gap-1.5">
                <span class="text-xs font-semibold uppercase tracking-[0.15em] text-emerald-600">{{ t('cash_sales.section_label') }}</span>
                <h1 class="text-3xl font-bold text-slate-950">{{ t('cash_sales.title') }}</h1>
                <p class="max-w-3xl text-sm text-slate-600">{{ t('cash_sales.subtitle') }}</p>
            </header>

            <div class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
                    <span class="text-slate-500">{{ t('cash_sales.filters.date_from') }}</span>
                    <input type="date" v-model="fromDate" class="w-40 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" />
                </label>
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
                    <span class="text-slate-500">{{ t('cash_sales.filters.date_to') }}</span>
                    <input type="date" v-model="toDate" class="w-40 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" />
                </label>
                <button
                    type="button"
                    class="ms-auto inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:opacity-50"
                    :disabled="loading"
                    @click="fetchSummary"
                >
                    <Search class="size-4" />
                    {{ loading ? t('cash_sales.filters.running') : t('cash_sales.filters.run') }}
                </button>
            </div>

            <div v-if="error" class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">{{ error }}</div>

            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="hidden border-b border-slate-200 bg-slate-50 px-5 py-2 text-xs uppercase tracking-wide text-slate-500 sm:grid sm:grid-cols-[1fr_repeat(4,minmax(100px,120px))_36px] sm:gap-2">
                    <span>{{ t('cash_sales.merchant') }}</span>
                    <span class="text-end">{{ t('cash_sales.sales') }}</span>
                    <span class="text-end">{{ t('cash_sales.cash') }}</span>
                    <span class="text-end">{{ t('cash_sales.bank_pos') }}</span>
                    <span class="text-end">{{ t('cash_sales.gross') }}</span>
                    <span />
                </div>

                <div v-for="m in summary" :key="m.company_uuid" class="border-b border-slate-100 last:border-0">
                    <button
                        type="button"
                        class="grid w-full items-center gap-2 px-5 py-3 text-left transition hover:bg-slate-50 sm:grid-cols-[1fr_repeat(4,minmax(100px,120px))_36px]"
                        @click="toggle(m.company_uuid)"
                    >
                        <span class="flex items-center gap-2 font-medium text-slate-900">
                            <Building2 class="size-4 text-slate-400" />
                            {{ m.company_name }}
                            <span
                                v-if="m.commissioned_count > 0"
                                class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 ring-inset"
                                :class="m.verified_count >= m.commissioned_count ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-amber-50 text-amber-700 ring-amber-200'"
                            >{{ m.verified_count >= m.commissioned_count ? t('cash_sales.completed') : t('cash_sales.verified_progress', { verified: m.verified_count, total: m.commissioned_count }) }}</span>
                        </span>
                        <span class="text-end text-sm tabular-nums text-slate-600">{{ m.sales_count }}</span>
                        <span class="text-end text-sm tabular-nums text-emerald-700">{{ m.cash_total }}</span>
                        <span class="text-end text-sm tabular-nums text-sky-700">{{ m.bank_pos_total }}</span>
                        <span class="text-end font-semibold tabular-nums text-slate-900">{{ m.gross_total }}</span>
                        <span class="flex justify-end">
                            <ChevronDown v-if="expanded.has(m.company_uuid)" class="size-4 text-slate-400" />
                            <ChevronRight v-else class="size-4 text-slate-400" />
                        </span>
                    </button>

                    <div v-if="expanded.has(m.company_uuid)" class="bg-slate-50/60 px-5 pb-2">
                        <RouterLink
                            v-for="b in m.branches"
                            :key="b.branch_uuid"
                            :to="verifyTo(m, b.branch_uuid, b.branch_name)"
                            class="grid items-center gap-2 rounded-lg px-4 py-2.5 text-sm transition hover:bg-white sm:grid-cols-[1fr_repeat(4,minmax(100px,120px))_36px]"
                        >
                            <span class="flex items-center gap-2 text-slate-800">
                                <ListChecks class="size-4 text-slate-400" />
                                {{ b.branch_name }}
                                <span
                                    v-if="b.commissioned_count > 0"
                                    class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 ring-inset"
                                    :class="b.verified_count >= b.commissioned_count ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-amber-50 text-amber-700 ring-amber-200'"
                                >{{ b.verified_count >= b.commissioned_count ? t('cash_sales.completed') : t('cash_sales.verified_progress', { verified: b.verified_count, total: b.commissioned_count }) }}</span>
                            </span>
                            <span class="text-end tabular-nums text-slate-500">{{ b.sales_count }}</span>
                            <span class="text-end tabular-nums text-emerald-700">{{ b.cash_total }}</span>
                            <span class="text-end tabular-nums text-sky-700">{{ b.bank_pos_total }}</span>
                            <span class="text-end font-semibold tabular-nums text-slate-800">{{ b.gross_total }}</span>
                            <span class="flex justify-end"><ChevronRight class="size-4 text-slate-400" /></span>
                        </RouterLink>
                    </div>
                </div>

                <div v-if="!summary.length && !loading" class="flex flex-col items-center gap-2 p-8 text-center text-sm text-slate-500">
                    <Banknote class="size-6 text-slate-300" />
                    {{ t('cash_sales.no_sales') }}
                </div>
                <div v-else-if="loading && !summary.length" class="p-8 text-center text-sm text-slate-500">{{ t('cash_sales.filters.running') }}</div>
            </div>
        </div>
    </AdminLayout>
</template>
