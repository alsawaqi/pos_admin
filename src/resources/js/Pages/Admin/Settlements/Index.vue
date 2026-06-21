<script setup lang="ts">
/**
 * Settlements — the daily reconciliation job, kept deliberately simple.
 *
 * One drill-down: merchants that have card sales to settle → their branches →
 * the per-order worklist (Reconcile.vue) where you check each sale against the
 * bank statement, fix any fee mismatch, Save, then Pay out. A small read-only
 * list of recent payouts (with Mark paid) sits below. settings.manage gated.
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import { CheckCircle2, ChevronDown, ChevronRight, ListChecks, Search } from 'lucide-vue-next';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { ApiError } from '@/lib/api';
import { listPendingSettlement, type PendingMerchant } from '@/lib/api/commissionSettlements';
import { listPayouts, markPayoutPaid, type PayoutRow, type PayoutStatus } from '@/lib/api/payouts';
import { usePermissions } from '@/composables/usePermissions';
import { PlatformPermission } from '@/lib/permissions';

const { t } = useI18n();
const { can } = usePermissions();
const canManage = computed(() => can(PlatformPermission.SettingsManage));

function isoOffsetDays(offsetDays: number): string {
    const d = new Date();
    d.setDate(d.getDate() - offsetDays);
    return d.toISOString().slice(0, 10);
}

// Default to today — this is a daily job.
const fromDate = ref(isoOffsetDays(0));
const toDate = ref(isoOffsetDays(0));

const merchants = ref<PendingMerchant[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);
const expanded = ref<Set<string>>(new Set());
const notice = ref<{ type: 'success' | 'error'; text: string } | null>(null);

const payouts = ref<PayoutRow[]>([]);
const payoutsLoading = ref(false);

async function fetchPending(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const r = await listPendingSettlement(fromDate.value, toDate.value);
        merchants.value = r.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : t('settlements.simple.load_failed');
    } finally {
        loading.value = false;
    }
}

async function fetchPayouts(): Promise<void> {
    payoutsLoading.value = true;
    try {
        const r = await listPayouts();
        payouts.value = r.data;
    } catch (err) {
        if (!(err instanceof ApiError)) throw err;
    } finally {
        payoutsLoading.value = false;
    }
}

function run(): void {
    void fetchPending();
    void fetchPayouts();
}
onMounted(run);

function toggle(uuid: string): void {
    const next = new Set(expanded.value);
    next.has(uuid) ? next.delete(uuid) : next.add(uuid);
    expanded.value = next;
}

function reconcileTo(m: PendingMerchant, branchUuid: string, branchName: string): string {
    const q = new URLSearchParams({
        company: m.company_uuid,
        company_name: m.company_name,
        branch: branchUuid,
        branch_name: branchName,
        from: fromDate.value,
        to: toDate.value,
    });
    return `/admin/settlements/reconcile?${q.toString()}`;
}

const STATUS_BADGE: Record<PayoutStatus, string> = {
    pending: 'bg-amber-50 text-amber-700 ring-amber-200',
    paid: 'bg-teal-50 text-teal-700 ring-teal-200',
    cancelled: 'bg-slate-100 text-slate-500 ring-slate-200',
};

function shortDate(iso: string | null): string {
    return iso ? iso.slice(0, 10) : '—';
}

async function onMarkPaid(p: PayoutRow): Promise<void> {
    notice.value = null;
    try {
        await markPayoutPaid(p.uuid);
        notice.value = { type: 'success', text: t('settlements.simple.paid_notice') };
        await fetchPayouts();
    } catch (err) {
        const msg = err instanceof ApiError ? (err.firstValidationMessage() ?? err.message) : err instanceof Error ? err.message : t('settlements.simple.load_failed');
        notice.value = { type: 'error', text: msg };
    }
}
</script>

<template>
    <AdminLayout>
        <div class="max-w-5xl">
            <header class="mb-5 flex flex-col gap-1.5">
                <span class="text-xs font-semibold uppercase tracking-[0.15em] text-indigo-600">{{ t('settlements.section_label') }}</span>
                <h1 class="text-3xl font-bold text-slate-950">{{ t('settlements.simple.title') }}</h1>
                <p class="max-w-3xl text-sm text-slate-600">{{ t('settlements.simple.subtitle') }}</p>
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
                <button type="button" class="ms-auto inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-50" :disabled="loading" @click="run">
                    <Search class="size-4" /> {{ loading ? t('settlements.filters.running') : t('settlements.filters.run') }}
                </button>
            </div>

            <div v-if="error" class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">{{ error }}</div>
            <div
                v-if="notice"
                class="mb-4 flex items-start justify-between gap-3 rounded-lg border px-4 py-3 text-sm font-semibold"
                :class="notice.type === 'success' ? 'border-teal-200 bg-teal-50 text-teal-800' : 'border-rose-200 bg-rose-50 text-rose-800'"
            >
                <span>{{ notice.text }}</span>
                <button type="button" class="text-current opacity-60 transition hover:opacity-100" @click="notice = null">×</button>
            </div>

            <!-- Merchants with sales to settle → branches → reconcile -->
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('settlements.simple.to_settle') }}</h2>
                <div v-for="m in merchants" :key="m.company_uuid" class="border-b border-slate-100 last:border-0">
                    <button type="button" class="flex w-full items-center gap-3 px-5 py-3 text-left transition hover:bg-slate-50" @click="toggle(m.company_uuid)">
                        <ChevronDown v-if="expanded.has(m.company_uuid)" class="size-4 text-slate-400" />
                        <ChevronRight v-else class="size-4 text-slate-400" />
                        <span class="font-medium text-slate-900">{{ m.company_name }}</span>
                        <span class="ms-auto text-sm text-slate-500">{{ t('settlements.simple.n_sales', { n: m.pending_orders }) }}</span>
                        <span class="w-28 text-end font-semibold tabular-nums text-indigo-900">{{ m.pending_net }} <span class="text-xs font-normal text-slate-400">{{ t('settlements.currency') }}</span></span>
                    </button>

                    <div v-if="expanded.has(m.company_uuid)" class="bg-slate-50/60 px-5 pb-2">
                        <RouterLink
                            v-for="b in m.branches"
                            :key="b.branch_uuid"
                            :to="reconcileTo(m, b.branch_uuid, b.branch_name)"
                            class="flex items-center gap-3 rounded-lg px-4 py-2.5 text-sm transition hover:bg-white"
                        >
                            <ListChecks class="size-4 text-slate-400" />
                            <span class="text-slate-800">{{ b.branch_name }}</span>
                            <span class="ms-auto text-slate-500">{{ t('settlements.simple.n_sales', { n: b.pending_orders }) }}</span>
                            <span class="w-28 text-end font-semibold tabular-nums text-slate-800">{{ b.pending_net }} <span class="text-xs font-normal text-slate-400">{{ t('settlements.currency') }}</span></span>
                            <ChevronRight class="size-4 text-slate-400" />
                        </RouterLink>
                    </div>
                </div>

                <div v-if="!merchants.length && !loading" class="p-8 text-center text-sm text-slate-500">{{ t('settlements.simple.nothing_to_settle') }}</div>
                <div v-else-if="loading && !merchants.length" class="p-8 text-center text-sm text-slate-500">{{ t('settlements.filters.running') }}</div>
            </div>

            <!-- Recent payouts (read-only + mark paid) -->
            <section class="mt-8">
                <h2 class="mb-3 text-lg font-semibold text-slate-950">{{ t('settlements.simple.recent_payouts') }}</h2>
                <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                    <table v-if="payouts.length" class="w-full text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-2 text-start">{{ t('settlements.payouts.columns.merchant') }}</th>
                                <th class="px-5 py-2 text-start">{{ t('settlements.simple.branch') }}</th>
                                <th class="px-5 py-2 text-end">{{ t('settlements.payouts.columns.net_amount') }}</th>
                                <th class="px-5 py-2 text-center">{{ t('settlements.payouts.columns.status') }}</th>
                                <th class="px-5 py-2 text-start">{{ t('settlements.payouts.columns.paid_at') }}</th>
                                <th v-if="canManage" class="px-5 py-2 text-end">{{ t('settlements.payouts.columns.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in payouts" :key="p.uuid" class="border-b border-slate-100 last:border-0">
                                <td class="px-5 py-2 font-medium text-slate-900">{{ p.company_name ?? '—' }}</td>
                                <td class="px-5 py-2 text-slate-600">{{ p.branch_name ?? t('settlements.simple.all_branches') }}</td>
                                <td class="px-5 py-2 text-end font-semibold tabular-nums text-indigo-900">{{ p.net_amount }}</td>
                                <td class="px-5 py-2 text-center">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset" :class="STATUS_BADGE[p.status]">{{ t(`settlements.payouts.statuses.${p.status}`) }}</span>
                                </td>
                                <td class="px-5 py-2 whitespace-nowrap tabular-nums text-slate-500">{{ shortDate(p.paid_at) }}</td>
                                <td v-if="canManage" class="px-5 py-2 text-end">
                                    <button
                                        v-if="p.status === 'pending'"
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg bg-teal-700 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-teal-800"
                                        @click="onMarkPaid(p)"
                                    >
                                        <CheckCircle2 class="size-3.5" /> {{ t('settlements.payouts.mark_paid') }}
                                    </button>
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
    </AdminLayout>
</template>
