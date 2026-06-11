<script setup lang="ts">
/**
 * P-F7 — Pending Reconciliation approval queue (daily admin review).
 *
 * Lists ORDERS whose force-recorded Soft POS card tenders sit
 * pending_reconciliation. Approving an order confirms the money arrived
 * (per the bank file) and fires the sale's DEFERRED money effects:
 * the commission split + the charity round-up forwarding. Rejecting
 * records that the money never arrived (tenders marked failed) — it does
 * NOT void the order; the sale must be handled through the normal flows.
 *
 * settings.manage gated (sidebar hides the entry otherwise; the server
 * enforces the same). Separate page from the bank-file matching tool,
 * but both settle through the same backend actions.
 */
import { CheckCircle2, ChevronLeft, ChevronRight, Search, XCircle } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '@/Components/BaseModal.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { ApiError } from '@/lib/api';
import {
    approvePendingReconciliation,
    listPendingReconciliation,
    rejectPendingReconciliation,
    type PendingReconciliationOrderRow,
} from '@/lib/api/pendingReconciliation';
import type { PaginationMeta } from '@/lib/api/merchants';

const { t } = useI18n();

function todayIso(): string {
    return new Date().toISOString().slice(0, 10);
}

const rows = ref<PendingReconciliationOrderRow[]>([]);
const meta = ref<PaginationMeta | null>(null);
const totals = ref<{ orders: number; pending_amount: string } | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
const notice = ref<{ type: 'success' | 'error'; text: string } | null>(null);

const date = ref(todayIso());
const page = ref(1);

const selected = ref<Set<number>>(new Set());
const approving = ref(false);

// Reject confirm dialog state.
const rejectTarget = ref<PendingReconciliationOrderRow | null>(null);
const rejecting = ref(false);

async function fetchPage(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const res = await listPendingReconciliation({ page: page.value, date: date.value || undefined });
        rows.value = res.data;
        meta.value = res.meta;
        totals.value = res.totals;
        selected.value = new Set();
    } catch {
        error.value = t('pending_recon.load_failed');
    } finally {
        loading.value = false;
    }
}

function applyFilters(): void {
    page.value = 1;
    void fetchPage();
}

function goPage(p: number): void {
    page.value = p;
    void fetchPage();
}

onMounted(() => {
    void fetchPage();
});

function toggleRow(id: number): void {
    const next = new Set(selected.value);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }
    selected.value = next;
}

const allSelected = computed(
    () => rows.value.length > 0 && rows.value.every((row) => selected.value.has(row.id)),
);

function toggleAll(): void {
    selected.value = allSelected.value ? new Set() : new Set(rows.value.map((row) => row.id));
}

function messageOf(err: unknown, fallback: string): string {
    if (err instanceof ApiError) {
        return err.firstValidationMessage() ?? err.message;
    }

    return fallback;
}

async function approveOrders(orderIds: number[]): Promise<void> {
    if (orderIds.length === 0 || approving.value) {
        return;
    }
    approving.value = true;
    notice.value = null;
    try {
        const res = await approvePendingReconciliation(orderIds);
        const failures = res.data.effects.donation_forward_failures.length;
        notice.value = failures > 0
            ? { type: 'error', text: t('pending_recon.approved_with_forward_failures', { count: res.data.orders_approved, failures }) }
            : { type: 'success', text: t('pending_recon.approved_notice', { count: res.data.orders_approved }) };
        await fetchPage();
    } catch (err) {
        notice.value = { type: 'error', text: messageOf(err, t('pending_recon.approve_failed')) };
    } finally {
        approving.value = false;
    }
}

function approveSelected(): void {
    void approveOrders([...selected.value]);
}

async function confirmReject(): Promise<void> {
    const target = rejectTarget.value;
    if (target === null || rejecting.value) {
        return;
    }
    rejecting.value = true;
    notice.value = null;
    try {
        await rejectPendingReconciliation([target.id]);
        notice.value = { type: 'success', text: t('pending_recon.rejected_notice') };
        rejectTarget.value = null;
        await fetchPage();
    } catch (err) {
        notice.value = { type: 'error', text: messageOf(err, t('pending_recon.reject_failed')) };
        rejectTarget.value = null;
    } finally {
        rejecting.value = false;
    }
}

function shortId(uuid: string): string {
    return uuid.slice(0, 8).toUpperCase();
}

function formatDateTime(iso: string | null): string {
    if (!iso) return '—';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString();
}

function evidenceOf(row: PendingReconciliationOrderRow): string {
    const tender = row.tenders[0];
    if (!tender) return '—';
    const parts: string[] = [];
    if (tender.softpos_reference) parts.push(tender.softpos_reference);
    if (tender.softpos_auth_code) parts.push(`${t('pending_recon.auth')} ${tender.softpos_auth_code}`);
    return parts.length > 0 ? parts.join(' · ') : '—';
}
</script>

<template>
    <AdminLayout>
        <div class="max-w-7xl">
            <header class="mb-5 flex flex-col gap-1.5">
                <span class="text-xs font-semibold uppercase tracking-[0.15em] text-amber-600">{{ t('pending_recon.section_label') }}</span>
                <h1 class="text-3xl font-bold text-slate-950">{{ t('pending_recon.title') }}</h1>
                <p class="max-w-3xl text-sm text-slate-600">{{ t('pending_recon.subtitle') }}</p>
            </header>

            <!-- What approval triggers. -->
            <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                {{ t('pending_recon.banner') }}
            </div>

            <div class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
                    <span class="text-slate-500">{{ t('pending_recon.filters.date') }}</span>
                    <input type="date" v-model="date" class="w-40 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" />
                </label>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:opacity-50"
                    :disabled="loading"
                    @click="applyFilters"
                >
                    <Search class="size-4" />
                    {{ loading ? t('pending_recon.filters.running') : t('pending_recon.filters.run') }}
                </button>
                <button
                    type="button"
                    class="ms-auto inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-500 disabled:opacity-50"
                    :disabled="approving || selected.size === 0"
                    @click="approveSelected"
                >
                    <CheckCircle2 class="size-4" />
                    {{ approving ? t('pending_recon.approving') : t('pending_recon.approve_selected', { count: selected.size }) }}
                </button>
            </div>

            <div v-if="totals" class="mb-5 grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('pending_recon.totals.orders') }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-slate-950">{{ totals.orders }}</p>
                </div>
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">{{ t('pending_recon.totals.pending_amount') }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-amber-900">{{ totals.pending_amount }} <span class="text-sm font-medium">OMR</span></p>
                </div>
            </div>

            <div
                v-if="notice"
                class="mb-4 flex items-start justify-between gap-3 rounded-lg border px-4 py-3 text-sm"
                :class="notice.type === 'success' ? 'border-teal-200 bg-teal-50 text-teal-800' : 'border-rose-200 bg-rose-50 text-rose-800'"
            >
                <span>{{ notice.text }}</span>
                <button type="button" class="text-current opacity-60 transition hover:opacity-100" @click="notice = null">×</button>
            </div>

            <div v-if="error" class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">{{ error }}</div>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                <table v-if="rows.length" class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2">
                                <input type="checkbox" class="size-4 rounded border-slate-300" :checked="allSelected" @change="toggleAll" />
                            </th>
                            <th class="px-4 py-2 text-start">{{ t('pending_recon.columns.time') }}</th>
                            <th class="px-4 py-2 text-start">{{ t('pending_recon.columns.order') }}</th>
                            <th class="px-4 py-2 text-start">{{ t('pending_recon.columns.merchant') }}</th>
                            <th class="px-4 py-2 text-start">{{ t('pending_recon.columns.branch') }}</th>
                            <th class="px-4 py-2 text-start">{{ t('pending_recon.columns.device') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('pending_recon.columns.total') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('pending_recon.columns.pending') }}</th>
                            <th class="px-4 py-2 text-start">{{ t('pending_recon.columns.evidence') }}</th>
                            <th class="px-4 py-2 text-start">{{ t('pending_recon.columns.verdict') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('pending_recon.columns.roundup') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('pending_recon.columns.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" class="border-b border-slate-100 last:border-0" :class="selected.has(row.id) ? 'bg-emerald-50/50' : ''">
                            <td class="px-4 py-2 text-center">
                                <input type="checkbox" class="size-4 rounded border-slate-300" :checked="selected.has(row.id)" @change="toggleRow(row.id)" />
                            </td>
                            <td class="px-4 py-2 text-xs tabular-nums text-slate-600">{{ formatDateTime(row.opened_at) }}</td>
                            <td class="px-4 py-2 font-mono text-xs font-semibold text-slate-900">{{ shortId(row.uuid) }}</td>
                            <td class="px-4 py-2 font-medium text-slate-800">{{ row.company?.name ?? '—' }}</td>
                            <td class="px-4 py-2 text-slate-700">{{ row.branch?.name ?? '—' }}</td>
                            <td class="px-4 py-2 text-slate-700">{{ row.device_name ?? '—' }}</td>
                            <td class="px-4 py-2 text-end font-semibold tabular-nums text-slate-900">{{ row.grand_total }}</td>
                            <td class="px-4 py-2 text-end font-semibold tabular-nums text-amber-700">{{ row.pending_total }}</td>
                            <td class="px-4 py-2 font-mono text-xs text-slate-600">{{ evidenceOf(row) }}</td>
                            <td class="px-4 py-2">
                                <span
                                    v-if="row.tenders[0]?.bank_verdict"
                                    class="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-600"
                                >{{ row.tenders[0]?.bank_verdict }}</span>
                                <span v-else class="text-xs text-slate-400">—</span>
                            </td>
                            <td class="px-4 py-2 text-end text-xs tabular-nums text-slate-600">{{ row.roundup_total ?? '—' }}</td>
                            <td class="px-4 py-2">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1.5 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-100 disabled:opacity-50"
                                        :disabled="approving"
                                        @click="void approveOrders([row.id])"
                                    >
                                        <CheckCircle2 class="size-3.5" />
                                        {{ t('pending_recon.approve') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-1.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-100 disabled:opacity-50"
                                        :disabled="rejecting"
                                        @click="rejectTarget = row"
                                    >
                                        <XCircle class="size-3.5" />
                                        {{ t('pending_recon.reject') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-else-if="!loading" class="p-8 text-center text-sm text-slate-500">{{ t('pending_recon.no_rows') }}</div>
                <div v-else class="p-8 text-center text-sm text-slate-500">{{ t('pending_recon.filters.running') }}</div>

                <div v-if="meta" class="flex items-center justify-between border-t border-slate-200 px-5 py-3 text-xs text-slate-600">
                    <div>{{ t('pending_recon.pagination', { page: meta.current_page, last: meta.last_page, total: meta.total }) }}</div>
                    <div class="flex items-center gap-2">
                        <button type="button" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-semibold disabled:opacity-50" :disabled="meta.current_page <= 1 || loading" @click="goPage(meta.current_page - 1)">
                            <ChevronLeft class="size-3.5" /> {{ t('pending_recon.prev') }}
                        </button>
                        <button type="button" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-semibold disabled:opacity-50" :disabled="meta.current_page >= meta.last_page || loading" @click="goPage(meta.current_page + 1)">
                            {{ t('pending_recon.next') }} <ChevronRight class="size-3.5" />
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reject confirm: money never arrived. -->
        <BaseModal
            v-if="rejectTarget"
            :title="t('pending_recon.reject_title')"
            size="md"
            :loading="rejecting"
            @close="rejectTarget = null"
        >
            <p class="text-sm text-slate-700">
                {{ t('pending_recon.reject_message', { order: shortId(rejectTarget.uuid), amount: rejectTarget.pending_total }) }}
            </p>
            <p class="mt-3 text-sm font-medium text-slate-600">{{ t('pending_recon.reject_note') }}</p>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button
                        type="button"
                        class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                        :disabled="rejecting"
                        @click="rejectTarget = null"
                    >
                        {{ t('pending_recon.reject_keep') }}
                    </button>
                    <button
                        type="button"
                        class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-rose-500 disabled:opacity-50"
                        :disabled="rejecting"
                        @click="void confirmReject()"
                    >
                        {{ rejecting ? t('pending_recon.rejecting') : t('pending_recon.reject_confirm') }}
                    </button>
                </div>
            </template>
        </BaseModal>
    </AdminLayout>
</template>
