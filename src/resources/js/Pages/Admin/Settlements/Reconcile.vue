<script setup lang="ts">
/**
 * Per-order reconciliation worklist for ONE branch + day.
 *
 * The admin opens this from a branch row on the Settlements page. It lists every
 * card SALE (the round-up is shown apart — it's a charity donation, not a sale,
 * and carries no commission) with the bank-matching evidence (terminal + Soft
 * POS auth code). The admin ticks the orders they've confirmed against the bank
 * Excel, adjusts the actual bank fee per order where the bank took more/less
 * (each row defaults to the estimate), and submits — either one-by-one or
 * select-all. Settled orders drop off the list.
 *
 * Query: ?company=&branch=&from=&to= (+ company_name=&branch_name= for display).
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, RouterLink } from 'vue-router';
import { ArrowLeft, Loader2, Scale, Send } from 'lucide-vue-next';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { ApiError } from '@/lib/api';
import { listSettlementOrders, settleCommissionOrders, type SettlementOrderRow } from '@/lib/api/commissionSettlements';
import { createPayout } from '@/lib/api/payouts';
import { usePermissions } from '@/composables/usePermissions';
import { PlatformPermission } from '@/lib/permissions';

const { t } = useI18n();
const route = useRoute();
const { can } = usePermissions();
const canManage = computed(() => can(PlatformPermission.SettingsManage));

const companyUuid = computed(() => String(route.query.company ?? ''));
const branchUuid = computed(() => String(route.query.branch ?? ''));
const fromDate = computed(() => String(route.query.from ?? ''));
const toDate = computed(() => String(route.query.to ?? ''));
const companyName = computed(() => String(route.query.company_name ?? ''));
const branchName = computed(() => String(route.query.branch_name ?? ''));

const orders = ref<SettlementOrderRow[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);
const saving = ref(false);
const payingOut = ref(false);
const notice = ref<{ type: 'success' | 'error'; text: string } | null>(null);

// Per-order entered actual bank fee (default = the estimate) + selection.
const actuals = ref<Record<string, string>>({});
const selected = ref<Set<string>>(new Set());

function num(v: string | number | null | undefined): number {
    const n = typeof v === 'number' ? v : Number.parseFloat(String(v ?? '0'));
    return Number.isFinite(n) ? n : 0;
}
function fmt(n: number): string {
    return n.toFixed(3);
}

async function fetchOrders(): Promise<void> {
    if (!companyUuid.value || !branchUuid.value) {
        error.value = t('settlements.reconcile.missing_scope');
        return;
    }
    loading.value = true;
    error.value = null;
    try {
        const r = await listSettlementOrders({ companyUuid: companyUuid.value, branchUuid: branchUuid.value, from: fromDate.value, to: toDate.value });
        orders.value = r.data;
        const next: Record<string, string> = {};
        for (const o of r.data) {
            next[o.order_uuid] = o.estimated_bank; // default the actual fee to the estimate
        }
        actuals.value = next;
        selected.value = new Set();
    } catch (err) {
        error.value = err instanceof Error ? err.message : t('settlements.reconcile.load_failed');
    } finally {
        loading.value = false;
    }
}

onMounted(fetchOrders);

const allSelected = computed(() => orders.value.length > 0 && selected.value.size === orders.value.length);
function toggleAll(): void {
    selected.value = allSelected.value ? new Set() : new Set(orders.value.map((o) => o.order_uuid));
}
function toggleOne(uuid: string): void {
    const next = new Set(selected.value);
    next.has(uuid) ? next.delete(uuid) : next.add(uuid);
    selected.value = next;
}

/** Net to the merchant after the entered actual fee (pass-through). */
function rowNet(o: SettlementOrderRow): number {
    return num(o.estimated_merchant_net) + (num(o.estimated_bank) - num(actuals.value[o.order_uuid]));
}

const selectedRows = computed(() => orders.value.filter((o) => selected.value.has(o.order_uuid)));
const totals = computed(() => {
    const rows = selectedRows.value;
    return {
        count: rows.length,
        card: fmt(rows.reduce((s, o) => s + num(o.card_amount), 0)),
        estimatedBank: fmt(rows.reduce((s, o) => s + num(o.estimated_bank), 0)),
        actualBank: fmt(rows.reduce((s, o) => s + num(actuals.value[o.order_uuid]), 0)),
        net: fmt(rows.reduce((s, o) => s + rowNet(o), 0)),
    };
});

function shortTime(iso: string | null): string {
    if (!iso) return '—';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString();
}

async function settleSelected(): Promise<void> {
    if (selectedRows.value.length === 0) return;
    saving.value = true;
    notice.value = null;
    try {
        const res = await settleCommissionOrders({
            companyUuid: companyUuid.value,
            branchUuid: branchUuid.value,
            orders: selectedRows.value.map((o) => ({ order_uuid: o.order_uuid, actual_bank: actuals.value[o.order_uuid] ?? o.estimated_bank })),
        });
        notice.value = { type: 'success', text: t('settlements.reconcile.settled_notice', { count: res.data.orders_count }) };
        await fetchOrders();
    } catch (err) {
        const msg = err instanceof ApiError ? (err.firstValidationMessage() ?? err.message) : err instanceof Error ? err.message : t('settlements.reconcile.load_failed');
        notice.value = { type: 'error', text: msg };
    } finally {
        saving.value = false;
    }
}

/** Step 2: pay this branch's settled sales to the merchant. */
async function payOut(): Promise<void> {
    payingOut.value = true;
    notice.value = null;
    try {
        const res = await createPayout({ companyUuid: companyUuid.value, branchUuid: branchUuid.value, from: fromDate.value, to: toDate.value });
        notice.value = { type: 'success', text: t('settlements.reconcile.paid_out_notice', { amount: res.data.net_amount }) };
    } catch (err) {
        const msg = err instanceof ApiError ? (err.firstValidationMessage() ?? err.message) : err instanceof Error ? err.message : t('settlements.reconcile.load_failed');
        notice.value = { type: 'error', text: msg };
    } finally {
        payingOut.value = false;
    }
}
</script>

<template>
    <AdminLayout>
        <div class="max-w-7xl">
            <RouterLink to="/admin/settlements" class="mb-3 inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 transition hover:text-slate-800">
                <ArrowLeft class="size-4" /> {{ t('settlements.reconcile.back') }}
            </RouterLink>

            <header class="mb-5 flex flex-col gap-1.5">
                <span class="text-xs font-semibold uppercase tracking-[0.15em] text-indigo-600">{{ t('settlements.reconcile.section_label') }}</span>
                <h1 class="text-2xl font-bold text-slate-950">{{ branchName || t('settlements.reconcile.title') }}</h1>
                <p class="text-sm text-slate-600">
                    <span v-if="companyName">{{ companyName }} · </span>{{ fromDate }} → {{ toDate }}
                </p>
            </header>

            <div
                v-if="notice"
                class="mb-4 flex items-start justify-between gap-3 rounded-lg border px-4 py-3 text-sm font-semibold"
                :class="notice.type === 'success' ? 'border-teal-200 bg-teal-50 text-teal-800' : 'border-rose-200 bg-rose-50 text-rose-800'"
            >
                <span>{{ notice.text }}</span>
                <button type="button" class="text-current opacity-60 transition hover:opacity-100" @click="notice = null">×</button>
            </div>

            <div v-if="error" class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">{{ error }}</div>

            <!-- Action bar -->
            <div v-if="canManage" class="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                <span class="text-sm text-slate-600">
                    {{ t('settlements.reconcile.selected_summary', { count: totals.count }) }}
                </span>
                <span v-if="totals.count" class="text-sm text-slate-500">
                    · {{ t('settlements.reconcile.summary_estimated') }} {{ totals.estimatedBank }} →
                    {{ t('settlements.reconcile.summary_actual') }} <strong class="text-slate-800">{{ totals.actualBank }}</strong> ·
                    {{ t('settlements.reconcile.summary_net') }} <strong class="text-indigo-800">{{ totals.net }}</strong> {{ t('settlements.currency') }}
                </span>
                <button
                    type="button"
                    class="ms-auto inline-flex items-center gap-2 rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="saving || totals.count === 0"
                    @click="settleSelected"
                >
                    <Loader2 v-if="saving" class="size-4 animate-spin" />
                    <Scale v-else class="size-4" />
                    {{ saving ? t('settlements.reconcile.settling') : t('settlements.reconcile.settle_selected') }}
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="payingOut || orders.length > 0"
                    :title="orders.length > 0 ? t('settlements.reconcile.pay_out_blocked') : ''"
                    @click="payOut"
                >
                    <Loader2 v-if="payingOut" class="size-4 animate-spin" />
                    <Send v-else class="size-4" />
                    {{ payingOut ? t('settlements.reconcile.paying_out') : t('settlements.reconcile.pay_out') }}
                </button>
            </div>
            <p v-if="canManage && orders.length > 0" class="-mt-2 mb-4 text-xs text-slate-500">{{ t('settlements.reconcile.pay_out_blocked') }}</p>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                <table v-if="orders.length" class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th v-if="canManage" class="px-4 py-2 text-center">
                                <input type="checkbox" :checked="allSelected" @change="toggleAll" />
                            </th>
                            <th class="px-4 py-2 text-start">{{ t('settlements.reconcile.cols.order') }}</th>
                            <th class="px-4 py-2 text-start">{{ t('settlements.reconcile.cols.time') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('settlements.reconcile.cols.card_sale') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('settlements.reconcile.cols.roundup') }}</th>
                            <th class="px-4 py-2 text-start">{{ t('settlements.reconcile.cols.terminal') }}</th>
                            <th class="px-4 py-2 text-start">{{ t('settlements.reconcile.cols.auth') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('settlements.reconcile.cols.est_bank') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('settlements.reconcile.cols.actual_bank') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('settlements.reconcile.cols.net') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="o in orders" :key="o.order_uuid" class="border-b border-slate-100 last:border-0" :class="selected.has(o.order_uuid) ? 'bg-amber-50/40' : ''">
                            <td v-if="canManage" class="px-4 py-2 text-center">
                                <input type="checkbox" :checked="selected.has(o.order_uuid)" @change="toggleOne(o.order_uuid)" />
                            </td>
                            <td class="px-4 py-2 font-medium text-slate-900">{{ o.receipt_number ?? '—' }}</td>
                            <td class="px-4 py-2 whitespace-nowrap text-slate-600">{{ shortTime(o.occurred_at) }}</td>
                            <td class="px-4 py-2 text-end tabular-nums text-slate-800">{{ o.card_amount }}</td>
                            <td class="px-4 py-2 text-end tabular-nums text-slate-400">{{ o.roundup }}</td>
                            <td class="px-4 py-2 text-slate-600">{{ o.tenders[0]?.terminal_id ?? '—' }}</td>
                            <td class="px-4 py-2 font-mono text-xs text-slate-600">{{ o.tenders.map((tn) => tn.auth_code).filter(Boolean).join(', ') || '—' }}</td>
                            <td class="px-4 py-2 text-end tabular-nums text-slate-500">{{ o.estimated_bank }}</td>
                            <td class="px-4 py-2 text-end">
                                <input
                                    v-if="canManage"
                                    v-model="actuals[o.order_uuid]"
                                    type="number"
                                    min="0"
                                    step="0.001"
                                    inputmode="decimal"
                                    class="w-24 rounded-lg border border-slate-300 px-2 py-1 text-end text-sm tabular-nums focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-100"
                                >
                                <span v-else class="tabular-nums text-slate-700">{{ o.settled_bank ?? o.estimated_bank }}</span>
                            </td>
                            <td class="px-4 py-2 text-end font-semibold tabular-nums text-indigo-900">{{ fmt(rowNet(o)) }}</td>
                        </tr>
                    </tbody>
                </table>

                <div v-else-if="loading" class="p-8 text-center text-sm text-slate-500">{{ t('settlements.filters.running') }}</div>
                <div v-else class="p-8 text-center text-sm text-slate-500">{{ t('settlements.reconcile.empty') }}</div>
            </div>
        </div>
    </AdminLayout>
</template>
