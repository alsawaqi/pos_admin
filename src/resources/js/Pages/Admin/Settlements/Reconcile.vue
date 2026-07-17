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
import { ArrowLeft, CreditCard, Loader2, Scale, Send } from 'lucide-vue-next';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { ApiError } from '@/lib/api';
import { listSettlementOrders, settleCommissionOrders, type SettlementOrderRow, type SettlementOrderStatus, type SettlementPaymentMethod } from '@/lib/api/commissionSettlements';
import { createPayout } from '@/lib/api/payouts';
import { createCommissionInvoice } from '@/lib/api/commissionInvoices';
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

// Per-order entered actual bank fee + platform commission (both default to the
// estimate) + selection. A3 — the operator can adjust the bank fee AND their own
// commission per sale; the merchant net is the residual (recomputed live).
const actuals = ref<Record<string, string>>({});
const platforms = ref<Record<string, string>>({});
const selected = ref<Set<string>>(new Set());

// Worklist filters. The MODE comes from the entry page and never mixes: the
// Sales drill opens the CARD workspace (verify against the bank statement →
// payout); the Cash & Bank POS page opens with ?pm=cash_bank (verify what the
// merchant collected → commission invoice). Card and cash flows are separate
// by design — the money runs in opposite directions.
const statusFilter = ref<SettlementOrderStatus>('unsettled');
const pmParam = String(route.query.pm ?? '');
const paymentMethod = ref<SettlementPaymentMethod>(pmParam === 'cash_bank' ? 'cash_bank' : pmParam === 'all' ? 'all' : 'card');
/** Cash & Bank POS mode — no bank fee, no payout; verification feeds the invoice. */
const cashMode = computed(() => paymentMethod.value === 'cash_bank');

// The active device/terminal tab ('' = all terminals).
const activeTerminal = ref<string>('');

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
        // Always fetch the FULL window (status 'all') — the state select is a
        // pure VIEW filter. Completion, the payout gate, and the invoice gate
        // must be judged on the whole window, never on a filtered view.
        const r = await listSettlementOrders({ companyUuid: companyUuid.value, branchUuid: branchUuid.value, from: fromDate.value, to: toDate.value, status: 'all', paymentMethod: paymentMethod.value });
        orders.value = r.data;
        const next: Record<string, string> = {};
        const nextPlatform: Record<string, string> = {};
        for (const o of r.data) {
            // Pre-fill the actual fee from the imported bank statement (A2) when
            // captured, else default to the estimate.
            next[o.order_uuid] = o.suggested_bank ?? o.estimated_bank;
            nextPlatform[o.order_uuid] = o.estimated_platform; // default platform to the estimate
        }
        actuals.value = next;
        platforms.value = nextPlatform;
        selected.value = new Set();
        // Drop the active tab if its terminal vanished from the fresh worklist
        // (e.g. every sale on it settled and the status filter hides them).
        if (activeTerminal.value !== '' && !r.data.some((o) => (o.terminal_id ?? '__none__') === activeTerminal.value)) {
            activeTerminal.value = '';
        }
    } catch (err) {
        error.value = err instanceof Error ? err.message : t('settlements.reconcile.load_failed');
    } finally {
        loading.value = false;
    }
}

onMounted(fetchOrders);

// The state select filters the DISPLAY only — the whole window stays loaded.
const displayedOrders = computed(() => {
    if (statusFilter.value === 'settled') return orders.value.filter((o) => o.is_settled);
    if (statusFilter.value === 'unsettled') return orders.value.filter((o) => !o.is_settled && !o.is_paid_out);
    return orders.value;
});

// Step 3 — EVERY unsettled commissioned sale is verifiable one-by-one: card
// sales verify against the bank statement (editable fee); cash/bank-POS sales
// verify at fee 0 with the platform commission still confirmable/editable.
// Computed on the FULL window, view-independent.
const reconcilableRows = computed(() => orders.value.filter((o) => !o.is_settled && !o.is_paid_out));
// Only CARD sales still awaiting reconciliation gate the payout (cash never
// blocks it — cash money is not in the payout; it goes to the invoice instead).
const pendingReconcile = computed(() => orders.value.filter((o) => o.needs_reconciliation && !o.is_settled).length);
// Branch verification progress → the Completed / Pending state (admin-side).
const verifiedCount = computed(() => orders.value.filter((o) => o.is_settled).length);
const branchComplete = computed(() => orders.value.length > 0 && reconcilableRows.value.length === 0);
const payoutBlockedReason = computed(() => (pendingReconcile.value > 0 ? t('settlements.reconcile.pay_out_blocked') : ''));
const payoutBlocked = computed(() => payoutBlockedReason.value !== '');
function toggleOne(uuid: string): void {
    const next = new Set(selected.value);
    next.has(uuid) ? next.delete(uuid) : next.add(uuid);
    selected.value = next;
}

/**
 * A blank/absent fee input means "accept the estimate", never zero — a cleared
 * `type=number` binds to '' (not null), so `?? estimate` would not fall back and
 * `num('')` collapses to 0, inflating the displayed net above what the server
 * (which keeps the estimate for a null override) will actually store.
 */
function orEstimate(v: string | number | null | undefined, estimate: string): string {
    return v === '' || v == null ? estimate : String(v);
}
/** Merchant net = residual: estimate, absorbing the bank AND platform variance. */
function rowNet(o: SettlementOrderRow): number {
    return (
        num(o.estimated_merchant_net) +
        (num(o.estimated_bank) - num(orEstimate(actuals.value[o.order_uuid], o.estimated_bank))) +
        (num(o.estimated_platform) - num(orEstimate(platforms.value[o.order_uuid], o.estimated_platform)))
    );
}

const selectedRows = computed(() => orders.value.filter((o) => selected.value.has(o.order_uuid)));
const totals = computed(() => {
    const rows = selectedRows.value;
    return {
        count: rows.length,
        card: fmt(rows.reduce((s, o) => s + num(o.card_amount), 0)),
        estimatedBank: fmt(rows.reduce((s, o) => s + num(o.estimated_bank), 0)),
        actualBank: fmt(rows.reduce((s, o) => s + num(orEstimate(actuals.value[o.order_uuid], o.estimated_bank)), 0)),
        actualPlatform: fmt(rows.reduce((s, o) => s + num(orEstimate(platforms.value[o.order_uuid], o.estimated_platform)), 0)),
        net: fmt(rows.reduce((s, o) => s + rowNet(o), 0)),
    };
});

// --- Per-terminal drill (Phase A1) --------------------------------------
// A branch may run several devices, each with its own terminal id and its own
// bank settlement. Group the worklist per terminal so the admin cross-references
// one bank terminal at a time, with a running subtotal per terminal.
interface TerminalGroup {
    key: string;
    terminalId: string | null;
    deviceName: string | null;
    orders: SettlementOrderRow[];
    reconcilable: SettlementOrderRow[];
    cardTotal: number;
    cashTotal: number;
    bankPosTotal: number;
    /** Charity round-up riding this terminal's card charges — NOT a sale, but the
     *  bank remits it in the same lump, so the statement matches card + roundup. */
    roundupTotal: number;
    estBank: number;
    net: number;
}

// Card mode carries one extra column: the per-row bank total (card + round-up).
const totalCols = computed(() => (canManage.value ? 11 : 10) + (cashMode.value ? 0 : 1));

const terminalGroups = computed<TerminalGroup[]>(() => {
    const map = new Map<string, TerminalGroup>();
    for (const o of displayedOrders.value) {
        const key = o.terminal_id ?? '__none__';
        let g = map.get(key);
        if (!g) {
            g = { key, terminalId: o.terminal_id, deviceName: o.device_name, orders: [], reconcilable: [], cardTotal: 0, cashTotal: 0, bankPosTotal: 0, roundupTotal: 0, estBank: 0, net: 0 };
            map.set(key, g);
        }
        g.deviceName ??= o.device_name;
        g.orders.push(o);
        if (!o.is_settled && !o.is_paid_out) g.reconcilable.push(o);
        g.cardTotal += num(o.card_amount);
        g.cashTotal += num(o.cash_amount);
        g.bankPosTotal += num(o.bank_pos_amount);
        g.roundupTotal += num(o.roundup);
        g.estBank += num(o.estimated_bank);
        g.net += rowNet(o);
    }
    // Real terminals first (by id); the "no terminal" bucket last.
    return [...map.values()].sort((a, b) => {
        if (a.terminalId === null) return 1;
        if (b.terminalId === null) return -1;
        return String(a.terminalId).localeCompare(String(b.terminalId));
    });
});

// One tab per device/terminal (the admin verifies one terminal at a time,
// exactly as the bank statement is read) + an "all" tab.
const visibleGroups = computed<TerminalGroup[]>(() =>
    activeTerminal.value === '' ? terminalGroups.value : terminalGroups.value.filter((g) => g.key === activeTerminal.value),
);

function tabLabel(g: TerminalGroup): string {
    const device = g.deviceName ?? t('settlements.reconcile.unknown_device');
    return g.terminalId ? `${device} · ${g.terminalId}` : `${device} · ${t('settlements.reconcile.no_terminal')}`;
}

// The header select-all covers exactly the rows the admin can SEE — the active
// tab's verifiable rows — never rows hidden behind another terminal tab.
const visibleReconcilable = computed(() => visibleGroups.value.flatMap((g) => g.reconcilable));
const allSelected = computed(() => visibleReconcilable.value.length > 0 && visibleReconcilable.value.every((o) => selected.value.has(o.order_uuid)));
function toggleAll(): void {
    const next = new Set(selected.value);
    const all = allSelected.value;
    for (const o of visibleReconcilable.value) {
        all ? next.delete(o.order_uuid) : next.add(o.order_uuid);
    }
    selected.value = next;
}

function terminalAllSelected(g: TerminalGroup): boolean {
    return g.reconcilable.length > 0 && g.reconcilable.every((o) => selected.value.has(o.order_uuid));
}
function toggleTerminal(g: TerminalGroup): void {
    const next = new Set(selected.value);
    const all = terminalAllSelected(g);
    for (const o of g.reconcilable) {
        all ? next.delete(o.order_uuid) : next.add(o.order_uuid);
    }
    selected.value = next;
}

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
            orders: selectedRows.value.map((o) => ({
                order_uuid: o.order_uuid,
                actual_bank: orEstimate(actuals.value[o.order_uuid], o.estimated_bank),
                actual_platform: orEstimate(platforms.value[o.order_uuid], o.estimated_platform),
            })),
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

/** Card mode step 2: pay this branch's settled sales to the merchant. */
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

// Cash mode step 2: BILL the verified commission to the merchant (the reverse
// direction — they hold the money, they owe the platform its cut).
const issuingInvoice = ref(false);
async function issueInvoice(): Promise<void> {
    issuingInvoice.value = true;
    notice.value = null;
    try {
        const res = await createCommissionInvoice({ companyUuid: companyUuid.value, branchUuid: branchUuid.value, from: fromDate.value, to: toDate.value });
        notice.value = { type: 'success', text: t('settlements.reconcile.invoiced_notice', { amount: res.data.total_owed }) };
        await fetchOrders();
    } catch (err) {
        const msg = err instanceof ApiError ? (err.firstValidationMessage() ?? err.message) : err instanceof Error ? err.message : t('settlements.reconcile.load_failed');
        notice.value = { type: 'error', text: msg };
    } finally {
        issuingInvoice.value = false;
    }
}
</script>

<template>
    <AdminLayout>
        <div class="max-w-7xl">
            <RouterLink :to="cashMode ? '/admin/cash-sales' : '/admin/settlements'" class="mb-3 inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 transition hover:text-slate-800">
                <ArrowLeft class="size-4" /> {{ t('settlements.reconcile.back') }}
            </RouterLink>

            <header class="mb-5 flex flex-col gap-1.5">
                <span class="text-xs font-semibold uppercase tracking-[0.15em]" :class="cashMode ? 'text-emerald-600' : 'text-indigo-600'">
                    {{ cashMode ? t('settlements.reconcile.section_label_cash') : t('settlements.reconcile.section_label') }}
                </span>
                <h1 class="text-2xl font-bold text-slate-950">{{ branchName || t('settlements.reconcile.title') }}</h1>
                <p class="text-sm text-slate-600">
                    <span v-if="companyName">{{ companyName }} · </span>{{ fromDate }} → {{ toDate }}
                </p>
            </header>

            <!-- Worklist state filter. The card/cash mode comes from the entry
                 page and never mixes — the two money flows are separate. -->
            <div class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
                    <span class="text-slate-500">{{ t('settlements.reconcile.filters.status') }}</span>
                    <!-- Pure view filter — the full window stays loaded, so the
                         completion/payout/invoice gates never depend on the view. -->
                    <select v-model="statusFilter" class="w-44 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                        <option value="unsettled">{{ t('settlements.reconcile.filters.status_unsettled') }}</option>
                        <option value="settled">{{ t('settlements.reconcile.filters.status_settled') }}</option>
                        <option value="all">{{ t('settlements.reconcile.filters.status_all') }}</option>
                    </select>
                </label>
            </div>

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
                <!-- Step 3 — branch verification state: Completed once every sale
                     in view is verified, Pending otherwise (admin-side only). -->
                <span
                    v-if="orders.length"
                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset"
                    :class="branchComplete ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-amber-50 text-amber-700 ring-amber-200'"
                >
                    {{ branchComplete ? t('settlements.reconcile.branch_complete') : t('settlements.reconcile.branch_pending', { verified: verifiedCount, total: orders.length }) }}
                </span>
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
                <!-- Card mode: pay the merchant their card money. Cash mode: BILL
                     the merchant the commission on money they already hold. -->
                <button
                    v-if="!cashMode"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="payingOut || payoutBlocked"
                    :title="payoutBlockedReason"
                    @click="payOut"
                >
                    <Loader2 v-if="payingOut" class="size-4 animate-spin" />
                    <Send v-else class="size-4" />
                    {{ payingOut ? t('settlements.reconcile.paying_out') : t('settlements.reconcile.pay_out') }}
                </button>
                <button
                    v-else
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="issuingInvoice || !branchComplete"
                    :title="!branchComplete ? t('settlements.reconcile.invoice_blocked') : ''"
                    @click="issueInvoice"
                >
                    <Loader2 v-if="issuingInvoice" class="size-4 animate-spin" />
                    <Send v-else class="size-4" />
                    {{ issuingInvoice ? t('settlements.reconcile.invoicing') : t('settlements.reconcile.issue_invoice') }}
                </button>
            </div>
            <p v-if="canManage && !cashMode && payoutBlocked" class="-mt-2 mb-4 text-xs text-slate-500">{{ payoutBlockedReason }}</p>
            <p v-if="canManage && cashMode && !branchComplete && orders.length" class="-mt-2 mb-4 text-xs text-slate-500">{{ t('settlements.reconcile.invoice_blocked') }}</p>

            <!-- Device / terminal tabs — verify one terminal at a time, exactly as
                 the bank statement is read. -->
            <div v-if="terminalGroups.length > 1" class="mb-3 flex flex-wrap gap-1.5">
                <button
                    type="button"
                    class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                    :class="activeTerminal === '' ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'"
                    @click="activeTerminal = ''"
                >{{ t('settlements.reconcile.all_terminals') }}</button>
                <button
                    v-for="g in terminalGroups"
                    :key="g.key"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                    :class="activeTerminal === g.key ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'"
                    @click="activeTerminal = g.key"
                >
                    <CreditCard class="size-3.5" :class="activeTerminal === g.key ? 'text-white' : 'text-slate-400'" />
                    {{ tabLabel(g) }}
                    <span class="rounded-full px-1.5 text-[10px]" :class="activeTerminal === g.key ? 'bg-white/20' : 'bg-slate-100 text-slate-500'">{{ g.orders.length }}</span>
                </button>
            </div>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                <table v-if="displayedOrders.length" class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th v-if="canManage" class="px-4 py-2 text-center">
                                <input type="checkbox" :checked="allSelected" @change="toggleAll" />
                            </th>
                            <th class="px-4 py-2 text-start">{{ t('settlements.reconcile.cols.order') }}</th>
                            <th class="px-4 py-2 text-start">{{ t('settlements.reconcile.cols.time') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('settlements.reconcile.cols.card_sale') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('settlements.reconcile.cols.roundup') }}</th>
                            <!-- What the bank actually charged for this transaction:
                                 sale + round-up in one lump — match THIS against the
                                 statement line. -->
                            <th v-if="!cashMode" class="px-4 py-2 text-end">{{ t('settlements.reconcile.cols.card_total') }}</th>
                            <th class="px-4 py-2 text-start">{{ t('settlements.reconcile.cols.terminal') }}</th>
                            <th class="px-4 py-2 text-start">{{ t('settlements.reconcile.cols.auth') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('settlements.reconcile.cols.est_bank') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('settlements.reconcile.cols.actual_bank') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('settlements.reconcile.cols.commission') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('settlements.reconcile.cols.net') }}</th>
                        </tr>
                    </thead>
                    <tbody v-for="g in visibleGroups" :key="g.key" class="border-b-4 border-slate-100 last:border-0">
                        <!-- Per-terminal group banner: one bank terminal at a time, with its own subtotal to match against that terminal's bank settlement. -->
                        <tr class="border-y border-slate-200 bg-slate-100/80">
                            <td :colspan="totalCols" class="px-4 py-2.5">
                                <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-800">
                                        <input v-if="canManage && g.reconcilable.length" type="checkbox" :checked="terminalAllSelected(g)" @change="toggleTerminal(g)" >
                                        <CreditCard class="size-4 text-slate-400" />
                                        <span>{{ tabLabel(g) }}</span>
                                    </label>
                                    <span class="text-xs text-slate-500">{{ t('settlements.reconcile.group_sales', { count: g.orders.length }) }}</span>
                                    <span class="ms-auto text-xs text-slate-600">
                                        <template v-if="!cashMode">
                                            {{ t('settlements.reconcile.group_card') }} <strong class="tabular-nums text-slate-800">{{ fmt(g.cardTotal) }}</strong>
                                            <!-- The bank's statement lump = sales + round-up (the bank can't
                                                 tell them apart) — match THIS figure against the statement,
                                                 then the round-up goes onward to charity. -->
                                            <template v-if="g.roundupTotal > 0">
                                                · {{ t('settlements.reconcile.group_roundup') }} <strong class="tabular-nums text-slate-800">{{ fmt(g.roundupTotal) }}</strong>
                                                · {{ t('settlements.reconcile.group_bank_total') }} <strong class="tabular-nums text-rose-700">{{ fmt(g.cardTotal + g.roundupTotal) }}</strong>
                                            </template>
                                            ·
                                        </template>
                                        <template v-else>
                                            <template v-if="g.cashTotal > 0">{{ t('settlements.reconcile.group_cash') }} <strong class="tabular-nums text-slate-800">{{ fmt(g.cashTotal) }}</strong> · </template>
                                            <template v-if="g.bankPosTotal > 0">{{ t('settlements.reconcile.group_bank_pos') }} <strong class="tabular-nums text-slate-800">{{ fmt(g.bankPosTotal) }}</strong> · </template>
                                        </template>
                                        <template v-if="!cashMode">{{ t('settlements.reconcile.group_est_bank') }} <strong class="tabular-nums text-slate-800">{{ fmt(g.estBank) }}</strong> · </template>
                                        {{ t('settlements.reconcile.group_net') }} <strong class="tabular-nums text-indigo-800">{{ fmt(g.net) }}</strong> {{ t('settlements.currency') }}
                                    </span>
                                </div>
                            </td>
                        </tr>
                        <tr v-for="o in g.orders" :key="o.order_uuid" class="border-b border-slate-100" :class="selected.has(o.order_uuid) ? 'bg-amber-50/40' : ''">
                            <td v-if="canManage" class="px-4 py-2 text-center">
                                <input v-if="!o.is_settled && !o.is_paid_out" type="checkbox" :checked="selected.has(o.order_uuid)" @change="toggleOne(o.order_uuid)" />
                                <span v-else class="text-xs text-slate-300">—</span>
                            </td>
                            <td class="px-4 py-2 font-medium text-slate-900">
                                {{ o.receipt_number ?? '—' }}
                                <!-- How the sale was paid — one chip per tender method. -->
                                <span v-if="num(o.cash_amount) > 0" class="ms-1.5 inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-700">{{ t('settlements.reconcile.cash_tag') }} {{ o.cash_amount }}</span>
                                <span v-if="num(o.bank_pos_amount) > 0" class="ms-1.5 inline-flex rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-700">{{ t('settlements.reconcile.bank_pos_tag') }} {{ o.bank_pos_amount }}</span>
                            </td>
                            <td class="px-4 py-2 whitespace-nowrap text-slate-600">{{ shortTime(o.occurred_at) }}</td>
                            <td class="px-4 py-2 text-end tabular-nums text-slate-800">{{ o.card_amount }}</td>
                            <td class="px-4 py-2 text-end tabular-nums text-slate-400">{{ o.roundup }}</td>
                            <td v-if="!cashMode" class="px-4 py-2 text-end font-semibold tabular-nums text-rose-700">{{ fmt(num(o.card_amount) + num(o.roundup)) }}</td>
                            <td class="px-4 py-2 text-slate-600">{{ o.tenders[0]?.terminal_id ?? '—' }}</td>
                            <td class="px-4 py-2 font-mono text-xs text-slate-600">{{ o.tenders.map((tn) => tn.auth_code).filter(Boolean).join(', ') || '—' }}</td>
                            <td class="px-4 py-2 text-end tabular-nums text-slate-500">{{ o.estimated_bank }}</td>
                            <td class="px-4 py-2 text-end">
                                <template v-if="canManage && o.needs_reconciliation && !o.is_settled">
                                    <input
                                        v-model="actuals[o.order_uuid]"
                                        type="number"
                                        min="0"
                                        step="0.001"
                                        inputmode="decimal"
                                        class="w-24 rounded-lg border px-2 py-1 text-end text-sm tabular-nums focus:outline-none focus:ring-2"
                                        :class="o.suggested_bank !== null ? 'border-teal-300 focus:border-teal-500 focus:ring-teal-100' : 'border-slate-300 focus:border-amber-500 focus:ring-amber-100'"
                                    >
                                    <div v-if="o.suggested_bank !== null" class="mt-0.5 text-[10px] font-medium text-teal-600">{{ t('settlements.reconcile.from_bank') }}</div>
                                </template>
                                <span v-else-if="!o.needs_reconciliation" class="text-xs text-slate-400">{{ t('settlements.reconcile.no_fee') }}</span>
                                <span v-else class="tabular-nums text-slate-700">{{ o.settled_bank ?? o.estimated_bank }}</span>
                            </td>
                            <td class="px-4 py-2 text-end">
                                <input
                                    v-if="canManage && !o.is_settled && !o.is_paid_out"
                                    v-model="platforms[o.order_uuid]"
                                    type="number"
                                    min="0"
                                    step="0.001"
                                    inputmode="decimal"
                                    class="w-24 rounded-lg border border-slate-300 px-2 py-1 text-end text-sm tabular-nums focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-100"
                                >
                                <span v-else class="tabular-nums text-slate-600">{{ (o.is_settled ? o.settled_platform : null) ?? o.estimated_platform }}</span>
                            </td>
                            <td class="px-4 py-2 text-end font-semibold tabular-nums text-indigo-900">{{ fmt(rowNet(o)) }}</td>
                        </tr>
                    </tbody>
                </table>

                <div v-else-if="loading" class="p-8 text-center text-sm text-slate-500">{{ t('settlements.filters.running') }}</div>
                <!-- Window HAS sales but the unsettled view is empty → everything
                     is verified. A truly empty window shows the plain empty state. -->
                <div v-else-if="orders.length && statusFilter === 'unsettled'" class="p-8 text-center text-sm font-semibold text-emerald-700">{{ t('settlements.reconcile.all_verified') }}</div>
                <div v-else class="p-8 text-center text-sm text-slate-500">{{ t('settlements.reconcile.empty') }}</div>
            </div>
        </div>
    </AdminLayout>
</template>
