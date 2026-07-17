<script setup lang="ts">
/**
 * Commission invoices — the reverse of payouts. For CASH / BANK_POS sales the
 * money went straight to the merchant, so the platform BILLS the merchant its
 * commission cut. One drill: merchants that owe commission → their branches →
 * "Issue invoice". A list of issued invoices (Mark paid / Void, one or in a
 * batch, expandable to the per-branch statement) sits below. settings.manage
 * gated for the money actions.
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Ban, CheckCircle2, ChevronDown, ChevronRight, FileText, Receipt, Search } from 'lucide-vue-next';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { ApiError } from '@/lib/api';
import {
    listPendingCommissionInvoices,
    listCommissionInvoices,
    createCommissionInvoice,
    markCommissionInvoicePaid,
    batchMarkCommissionInvoicesPaid,
    voidCommissionInvoice,
    getCommissionInvoiceLines,
    type PendingInvoiceMerchant,
    type CommissionInvoiceRow,
    type CommissionInvoiceStatus,
    type CommissionInvoiceLine,
} from '@/lib/api/commissionInvoices';
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

// Default to the current month — commission is typically billed per period.
const fromDate = ref(isoOffsetDays(30));
const toDate = ref(isoOffsetDays(0));

const merchants = ref<PendingInvoiceMerchant[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);
const expanded = ref<Set<string>>(new Set());
const notice = ref<{ type: 'success' | 'error'; text: string } | null>(null);
const issuing = ref<string | null>(null);

const invoices = ref<CommissionInvoiceRow[]>([]);
const invoicesLoading = ref(false);
const statusFilter = ref<CommissionInvoiceStatus | ''>('');
const merchantFilter = ref('');
const selected = ref<Set<string>>(new Set());
const batchMarking = ref(false);

// Per-invoice expandable statement lines.
const openLines = ref<Set<string>>(new Set());
const linesCache = ref<Record<string, CommissionInvoiceLine[]>>({});

async function fetchPending(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const r = await listPendingCommissionInvoices(fromDate.value, toDate.value);
        merchants.value = r.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : t('invoices.load_failed');
    } finally {
        loading.value = false;
    }
}

async function fetchInvoices(): Promise<void> {
    invoicesLoading.value = true;
    try {
        const r = await listCommissionInvoices({ status: statusFilter.value || undefined });
        invoices.value = r.data;
        selected.value = new Set();
    } catch (err) {
        if (!(err instanceof ApiError)) throw err;
    } finally {
        invoicesLoading.value = false;
    }
}

function run(): void {
    void fetchPending();
    void fetchInvoices();
}
onMounted(run);

function toggle(uuid: string): void {
    const next = new Set(expanded.value);
    next.has(uuid) ? next.delete(uuid) : next.add(uuid);
    expanded.value = next;
}

async function issueInvoice(companyUuid: string, branchUuid?: string): Promise<void> {
    const key = branchUuid ?? companyUuid;
    notice.value = null;
    issuing.value = key;
    try {
        const res = await createCommissionInvoice({ companyUuid, branchUuid, from: fromDate.value, to: toDate.value });
        notice.value = { type: 'success', text: t('invoices.issued_notice', { amount: res.data.total_owed }) };
        await fetchPending();
        await fetchInvoices();
    } catch (err) {
        const msg = err instanceof ApiError ? (err.firstValidationMessage() ?? err.message) : err instanceof Error ? err.message : t('invoices.load_failed');
        notice.value = { type: 'error', text: msg };
    } finally {
        issuing.value = null;
    }
}

const filteredInvoices = computed(() => {
    const q = merchantFilter.value.trim().toLowerCase();
    if (!q) return invoices.value;
    return invoices.value.filter((i) => (i.company_name ?? '').toLowerCase().includes(q));
});

const selectableInvoices = computed(() => filteredInvoices.value.filter((i) => i.status === 'issued'));
const allSelected = computed(() => selectableInvoices.value.length > 0 && selectableInvoices.value.every((i) => selected.value.has(i.uuid)));

function toggleSelection(uuid: string): void {
    const next = new Set(selected.value);
    next.has(uuid) ? next.delete(uuid) : next.add(uuid);
    selected.value = next;
}
function toggleAll(): void {
    selected.value = allSelected.value ? new Set() : new Set(selectableInvoices.value.map((i) => i.uuid));
}

async function onBatchMarkPaid(): Promise<void> {
    if (selected.value.size === 0) return;
    notice.value = null;
    batchMarking.value = true;
    try {
        const res = await batchMarkCommissionInvoicesPaid([...selected.value]);
        notice.value = { type: 'success', text: t('invoices.batch_paid_notice', { marked: res.data.marked, skipped: res.data.skipped }) };
        await fetchInvoices();
    } catch (err) {
        const msg = err instanceof ApiError ? (err.firstValidationMessage() ?? err.message) : err instanceof Error ? err.message : t('invoices.load_failed');
        notice.value = { type: 'error', text: msg };
    } finally {
        batchMarking.value = false;
    }
}

async function onMarkPaid(i: CommissionInvoiceRow): Promise<void> {
    notice.value = null;
    try {
        await markCommissionInvoicePaid(i.uuid);
        notice.value = { type: 'success', text: t('invoices.paid_notice') };
        await fetchInvoices();
    } catch (err) {
        const msg = err instanceof ApiError ? (err.firstValidationMessage() ?? err.message) : err instanceof Error ? err.message : t('invoices.load_failed');
        notice.value = { type: 'error', text: msg };
    }
}

async function onVoid(i: CommissionInvoiceRow): Promise<void> {
    notice.value = null;
    try {
        await voidCommissionInvoice(i.uuid);
        notice.value = { type: 'success', text: t('invoices.voided_notice') };
        await fetchPending();
        await fetchInvoices();
    } catch (err) {
        const msg = err instanceof ApiError ? (err.firstValidationMessage() ?? err.message) : err instanceof Error ? err.message : t('invoices.load_failed');
        notice.value = { type: 'error', text: msg };
    }
}

async function toggleLines(i: CommissionInvoiceRow): Promise<void> {
    const next = new Set(openLines.value);
    if (next.has(i.uuid)) {
        next.delete(i.uuid);
        openLines.value = next;
        return;
    }
    next.add(i.uuid);
    openLines.value = next;
    if (!linesCache.value[i.uuid]) {
        try {
            const r = await getCommissionInvoiceLines(i.uuid);
            linesCache.value = { ...linesCache.value, [i.uuid]: r.data };
        } catch (err) {
            if (!(err instanceof ApiError)) throw err;
        }
    }
}

const STATUS_BADGE: Record<CommissionInvoiceStatus, string> = {
    issued: 'bg-amber-50 text-amber-700 ring-amber-200',
    paid: 'bg-teal-50 text-teal-700 ring-teal-200',
    void: 'bg-slate-100 text-slate-500 ring-slate-200',
};

function shortDate(iso: string | null): string {
    return iso ? iso.slice(0, 10) : '—';
}
</script>

<template>
    <AdminLayout>
        <div class="max-w-5xl">
            <header class="mb-5 flex flex-col gap-1.5">
                <span class="text-xs font-semibold uppercase tracking-[0.15em] text-indigo-600">{{ t('invoices.section_label') }}</span>
                <h1 class="text-3xl font-bold text-slate-950">{{ t('invoices.title') }}</h1>
                <p class="max-w-3xl text-sm text-slate-600">{{ t('invoices.subtitle') }}</p>
            </header>

            <div class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
                    <span class="text-slate-500">{{ t('invoices.filters.date_from') }}</span>
                    <input type="date" v-model="fromDate" class="w-40 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" />
                </label>
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
                    <span class="text-slate-500">{{ t('invoices.filters.date_to') }}</span>
                    <input type="date" v-model="toDate" class="w-40 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" />
                </label>
                <button type="button" class="ms-auto inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-50" :disabled="loading" @click="run">
                    <Search class="size-4" /> {{ loading ? t('invoices.filters.running') : t('invoices.filters.run') }}
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

            <!-- Merchants that owe commission → branches → Issue invoice -->
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <h2 class="border-b border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700">{{ t('invoices.to_bill') }}</h2>
                <div v-for="m in merchants" :key="m.company_uuid" class="border-b border-slate-100 last:border-0">
                    <div class="flex w-full items-center gap-3 px-5 py-3">
                        <button type="button" class="flex flex-1 items-center gap-3 text-left" @click="toggle(m.company_uuid)">
                            <ChevronDown v-if="expanded.has(m.company_uuid)" class="size-4 text-slate-400" />
                            <ChevronRight v-else class="size-4 text-slate-400" />
                            <span class="font-medium text-slate-900">{{ m.company_name }}</span>
                            <span class="ms-auto text-sm text-slate-500">{{ t('invoices.n_sales', { n: m.pending_orders }) }}</span>
                        </button>
                        <span class="w-28 text-end font-semibold tabular-nums text-indigo-900">{{ m.pending_owed }} <span class="text-xs font-normal text-slate-400">{{ t('invoices.currency') }}</span></span>
                        <button
                            v-if="canManage"
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-50"
                            :disabled="issuing === m.company_uuid"
                            @click="issueInvoice(m.company_uuid)"
                        >
                            <Receipt class="size-3.5" /> {{ t('invoices.issue_all') }}
                        </button>
                    </div>

                    <div v-if="expanded.has(m.company_uuid)" class="bg-slate-50/60 px-5 pb-2">
                        <div
                            v-for="b in m.branches"
                            :key="b.branch_uuid"
                            class="flex items-center gap-3 rounded-lg px-4 py-2.5 text-sm"
                        >
                            <FileText class="size-4 text-slate-400" />
                            <span class="text-slate-800">{{ b.branch_name }}</span>
                            <span class="ms-auto text-slate-500">{{ t('invoices.n_sales', { n: b.pending_orders }) }}</span>
                            <span class="w-28 text-end font-semibold tabular-nums text-slate-800">{{ b.pending_owed }} <span class="text-xs font-normal text-slate-400">{{ t('invoices.currency') }}</span></span>
                            <button
                                v-if="canManage"
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-indigo-200 bg-white px-3 py-1.5 text-xs font-semibold text-indigo-700 transition hover:bg-indigo-50 disabled:opacity-50"
                                :disabled="issuing === b.branch_uuid"
                                @click="issueInvoice(m.company_uuid, b.branch_uuid)"
                            >
                                <Receipt class="size-3.5" /> {{ t('invoices.issue') }}
                            </button>
                        </div>
                    </div>
                </div>

                <div v-if="!merchants.length && !loading" class="p-8 text-center text-sm text-slate-500">{{ t('invoices.nothing_to_bill') }}</div>
                <div v-else-if="loading && !merchants.length" class="p-8 text-center text-sm text-slate-500">{{ t('invoices.filters.running') }}</div>
            </div>

            <!-- Issued invoices (filter + mark paid / void, one or in a batch) -->
            <section class="mt-8">
                <div class="mb-3 flex flex-wrap items-end gap-3">
                    <h2 class="text-lg font-semibold text-slate-950">{{ t('invoices.issued_invoices') }}</h2>
                    <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
                        <span class="text-slate-500">{{ t('invoices.filters.status') }}</span>
                        <select v-model="statusFilter" class="w-40 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm" @change="fetchInvoices">
                            <option value="">{{ t('invoices.filters.status_all') }}</option>
                            <option value="issued">{{ t('invoices.statuses.issued') }}</option>
                            <option value="paid">{{ t('invoices.statuses.paid') }}</option>
                            <option value="void">{{ t('invoices.statuses.void') }}</option>
                        </select>
                    </label>
                    <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
                        <span class="text-slate-500">{{ t('invoices.filters.merchant') }}</span>
                        <input v-model="merchantFilter" type="search" :placeholder="t('invoices.filters.merchant_placeholder')" class="w-52 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm" />
                    </label>
                    <button
                        v-if="canManage && selected.size > 0"
                        type="button"
                        class="ms-auto inline-flex items-center gap-1.5 rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-800 disabled:opacity-50"
                        :disabled="batchMarking"
                        @click="onBatchMarkPaid"
                    >
                        <CheckCircle2 class="size-4" /> {{ t('invoices.batch_mark_paid', { n: selected.size }) }}
                    </button>
                </div>
                <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                    <table v-if="filteredInvoices.length" class="w-full text-sm">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th v-if="canManage" class="px-4 py-2 text-center">
                                    <input type="checkbox" :checked="allSelected" :disabled="!selectableInvoices.length" @change="toggleAll" />
                                </th>
                                <th class="px-5 py-2 text-start">{{ t('invoices.columns.merchant') }}</th>
                                <th class="px-5 py-2 text-start">{{ t('invoices.columns.branch') }}</th>
                                <th class="px-5 py-2 text-end">{{ t('invoices.columns.total_owed') }}</th>
                                <th class="px-5 py-2 text-center">{{ t('invoices.columns.status') }}</th>
                                <th class="px-5 py-2 text-start">{{ t('invoices.columns.paid_at') }}</th>
                                <th class="px-5 py-2 text-end">{{ t('invoices.columns.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="i in filteredInvoices" :key="i.uuid">
                                <tr class="border-b border-slate-100 last:border-0">
                                    <td v-if="canManage" class="px-4 py-2 text-center">
                                        <input v-if="i.status === 'issued'" type="checkbox" :checked="selected.has(i.uuid)" @change="toggleSelection(i.uuid)" />
                                        <span v-else class="text-xs text-slate-300">—</span>
                                    </td>
                                    <td class="px-5 py-2 font-medium text-slate-900">
                                        <button type="button" class="inline-flex items-center gap-1.5 text-start hover:text-indigo-700" @click="toggleLines(i)">
                                            <ChevronDown v-if="openLines.has(i.uuid)" class="size-3.5 text-slate-400" />
                                            <ChevronRight v-else class="size-3.5 text-slate-400" />
                                            {{ i.company_name ?? '—' }}
                                        </button>
                                    </td>
                                    <td class="px-5 py-2 text-slate-600">{{ i.branch_name ?? t('invoices.all_branches') }}</td>
                                    <td class="px-5 py-2 text-end font-semibold tabular-nums text-indigo-900">{{ i.total_owed }}</td>
                                    <td class="px-5 py-2 text-center">
                                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset" :class="STATUS_BADGE[i.status]">{{ t(`invoices.statuses.${i.status}`) }}</span>
                                    </td>
                                    <td class="px-5 py-2 whitespace-nowrap tabular-nums text-slate-500">{{ shortDate(i.paid_at) }}</td>
                                    <td class="px-5 py-2 text-end">
                                        <div v-if="canManage && i.status === 'issued'" class="inline-flex items-center gap-1.5">
                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-1.5 rounded-lg bg-teal-700 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-teal-800"
                                                @click="onMarkPaid(i)"
                                            >
                                                <CheckCircle2 class="size-3.5" /> {{ t('invoices.mark_paid') }}
                                            </button>
                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-50"
                                                @click="onVoid(i)"
                                            >
                                                <Ban class="size-3.5" /> {{ t('invoices.void') }}
                                            </button>
                                        </div>
                                        <span v-else class="text-xs text-slate-400">—</span>
                                    </td>
                                </tr>
                                <tr v-if="openLines.has(i.uuid)" class="bg-slate-50/60">
                                    <td :colspan="canManage ? 7 : 6" class="px-8 py-2">
                                        <table class="w-full text-xs">
                                            <thead class="text-slate-400">
                                                <tr>
                                                    <th class="py-1 text-start font-medium">{{ t('invoices.columns.branch') }}</th>
                                                    <th class="py-1 text-end font-medium">{{ t('invoices.lines.platform') }}</th>
                                                    <th class="py-1 text-end font-medium">{{ t('invoices.lines.other') }}</th>
                                                    <th class="py-1 text-end font-medium">{{ t('invoices.lines.merchant_kept') }}</th>
                                                    <th class="py-1 text-end font-medium">{{ t('invoices.columns.total_owed') }}</th>
                                                    <th class="py-1 text-end font-medium">{{ t('invoices.n_sales_short') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="l in (linesCache[i.uuid] ?? [])" :key="l.branch_id" class="border-t border-slate-200/70">
                                                    <td class="py-1 text-slate-700">{{ l.branch_name }}</td>
                                                    <td class="py-1 text-end tabular-nums text-slate-600">{{ l.platform }}</td>
                                                    <td class="py-1 text-end tabular-nums text-slate-600">{{ l.other }}</td>
                                                    <td class="py-1 text-end tabular-nums text-slate-400">{{ l.merchant_kept }}</td>
                                                    <td class="py-1 text-end font-semibold tabular-nums text-indigo-900">{{ l.total_owed }}</td>
                                                    <td class="py-1 text-end tabular-nums text-slate-500">{{ l.num_sales }}</td>
                                                </tr>
                                                <tr v-if="!(linesCache[i.uuid] ?? []).length">
                                                    <td colspan="6" class="py-2 text-center text-slate-400">{{ t('invoices.no_lines') }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <div v-else-if="invoicesLoading" class="p-8 text-center text-sm text-slate-500">{{ t('invoices.filters.running') }}</div>
                    <div v-else class="p-8 text-center text-sm text-slate-500">{{ t('invoices.no_rows') }}</div>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
