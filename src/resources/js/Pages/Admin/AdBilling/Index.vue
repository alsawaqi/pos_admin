<script setup lang="ts">
/**
 * Phase 5 — advertiser billing (invoice-first). Top: the "to bill" drill —
 * advertisers whose content DELIVERED in the window, with metered plays /
 * screen-days and an estimate from their active rate card; Issue bills the
 * window. A rate-card editor sits inline per advertiser. Below: issued
 * invoices (Mark paid / Void, expandable per-branch delivery statement).
 * settings.manage gates the money actions; reads are reports.view.
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Ban, CheckCircle2, ChevronDown, ChevronRight, Megaphone } from 'lucide-vue-next';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { ApiError } from '@/lib/api';
import {
    fetchAdPending,
    fetchAdInvoices,
    fetchAdInvoiceLines,
    issueAdInvoice,
    markAdInvoicePaid,
    voidAdInvoice,
    setAdRateCard,
    type AdPendingRow,
    type AdInvoiceRow,
    type AdInvoiceLine,
    type AdInvoiceStatus,
    type AdPricingModel,
} from '@/lib/api/adBilling';
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

const fromDate = ref(isoOffsetDays(30));
const toDate = ref(isoOffsetDays(0));

const pending = ref<AdPendingRow[]>([]);
const loading = ref(false);
const notice = ref<{ type: 'success' | 'error'; text: string } | null>(null);
const issuing = ref<number | null>(null);

const invoices = ref<AdInvoiceRow[]>([]);
const invoicePage = ref(1);
const invoiceLastPage = ref(1);
const statusFilter = ref<AdInvoiceStatus | ''>('');
const openLines = ref<Set<string>>(new Set());
const linesByInvoice = ref<Record<string, AdInvoiceLine[]>>({});

// Inline rate-card editor state (one advertiser at a time).
const editingCard = ref<number | null>(null);
const cardModel = ref<AdPricingModel>('per_day_per_screen');
const cardRate = ref('');
const savingCard = ref(false);

function flash(type: 'success' | 'error', text: string): void {
    notice.value = { type, text };
    setTimeout(() => (notice.value = null), 6000);
}

async function loadPending(): Promise<void> {
    loading.value = true;
    try {
        pending.value = (await fetchAdPending(fromDate.value, toDate.value)).data;
    } catch (e) {
        flash('error', e instanceof ApiError ? e.message : String(e));
    } finally {
        loading.value = false;
    }
}

async function loadInvoices(page = 1): Promise<void> {
    try {
        const res = await fetchAdInvoices(statusFilter.value || undefined, page);
        invoices.value = res.data;
        invoicePage.value = res.current_page;
        invoiceLastPage.value = res.last_page;
    } catch (e) {
        flash('error', e instanceof ApiError ? e.message : String(e));
    }
}

async function issue(row: AdPendingRow): Promise<void> {
    issuing.value = row.advertiser_id;
    try {
        await issueAdInvoice({ advertiser_id: row.advertiser_id, from: fromDate.value, to: toDate.value });
        flash('success', t('adbilling.issued', { name: row.advertiser_name }));
        await Promise.all([loadPending(), loadInvoices()]);
    } catch (e) {
        flash('error', e instanceof ApiError ? e.message : String(e));
    } finally {
        issuing.value = null;
    }
}

async function markPaid(inv: AdInvoiceRow): Promise<void> {
    try {
        await markAdInvoicePaid(inv.uuid);
        flash('success', t('adbilling.marked_paid'));
        await loadInvoices();
    } catch (e) {
        flash('error', e instanceof ApiError ? e.message : String(e));
    }
}

async function voidInv(inv: AdInvoiceRow): Promise<void> {
    // prompt() returns null on Cancel/Escape — that is an ABORT, not a void
    // with no reason. Only an OK (any string, even empty) proceeds.
    const answer = window.prompt(t('adbilling.void_reason_prompt'));
    if (answer === null) return;
    const reason = answer || undefined;
    try {
        await voidAdInvoice(inv.uuid, reason);
        flash('success', t('adbilling.voided'));
        await Promise.all([loadPending(), loadInvoices()]);
    } catch (e) {
        flash('error', e instanceof ApiError ? e.message : String(e));
    }
}

async function toggleLines(inv: AdInvoiceRow): Promise<void> {
    if (openLines.value.has(inv.uuid)) {
        openLines.value.delete(inv.uuid);
        openLines.value = new Set(openLines.value);
        return;
    }
    if (!linesByInvoice.value[inv.uuid]) {
        try {
            linesByInvoice.value[inv.uuid] = (await fetchAdInvoiceLines(inv.uuid)).data;
        } catch (e) {
            flash('error', e instanceof ApiError ? e.message : String(e));
            return; // don't open an empty row
        }
    }
    openLines.value = new Set(openLines.value).add(inv.uuid);
}

function openCardEditor(row: AdPendingRow): void {
    editingCard.value = row.advertiser_id;
    cardModel.value = row.pricing_model ?? 'per_day_per_screen';
    cardRate.value = row.rate ?? '';
}

async function saveCard(): Promise<void> {
    if (editingCard.value === null) return;
    savingCard.value = true;
    try {
        await setAdRateCard({ advertiser_id: editingCard.value, pricing_model: cardModel.value, rate: cardRate.value });
        flash('success', t('adbilling.card_saved'));
        editingCard.value = null;
        await loadPending();
    } catch (e) {
        flash('error', e instanceof ApiError ? e.message : String(e));
    } finally {
        savingCard.value = false;
    }
}

function day(v: string | null): string {
    return v ? v.slice(0, 10) : '—';
}

onMounted(() => {
    void loadPending();
    void loadInvoices();
});
</script>

<template>
    <AdminLayout>
        <div class="space-y-6 p-6">
            <div class="flex items-center gap-3">
                <Megaphone class="h-7 w-7 text-teal-700" />
                <div>
                    <h1 class="text-xl font-bold text-slate-900">{{ t('adbilling.title') }}</h1>
                    <p class="text-sm text-slate-500">{{ t('adbilling.subtitle') }}</p>
                </div>
            </div>

            <div
                v-if="notice"
                class="rounded-lg px-4 py-3 text-sm font-medium"
                :class="notice.type === 'success' ? 'bg-emerald-50 text-emerald-800' : 'bg-rose-50 text-rose-800'"
            >{{ notice.text }}</div>

            <!-- To-bill drill -->
            <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-end gap-3 border-b border-slate-100 p-4">
                    <div>
                        <label class="block text-xs font-semibold uppercase text-slate-500">{{ t('adbilling.from') }}</label>
                        <input v-model="fromDate" type="date" class="rounded-lg border-slate-300 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase text-slate-500">{{ t('adbilling.to') }}</label>
                        <input v-model="toDate" type="date" class="rounded-lg border-slate-300 text-sm" />
                    </div>
                    <button
                        class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800"
                        :disabled="loading"
                        @click="loadPending"
                    >{{ t('adbilling.run') }}</button>
                </div>

                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2 text-start">{{ t('adbilling.advertiser') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('adbilling.plays') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('adbilling.screen_days') }}</th>
                            <th class="px-4 py-2 text-start">{{ t('adbilling.rate_card') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('adbilling.estimate') }}</th>
                            <th class="px-4 py-2 text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in pending" :key="row.advertiser_id" class="border-b border-slate-100 last:border-0">
                            <td class="px-4 py-2">
                                <div class="font-medium text-slate-900">{{ row.brand_name || row.advertiser_name }}</div>
                                <div class="text-xs text-slate-500">{{ row.advertiser_name }}</div>
                            </td>
                            <td class="px-4 py-2 text-end tabular-nums">{{ row.plays }}</td>
                            <td class="px-4 py-2 text-end tabular-nums">{{ row.screen_days }}</td>
                            <td class="px-4 py-2">
                                <template v-if="editingCard === row.advertiser_id">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <select v-model="cardModel" class="rounded-lg border-slate-300 text-xs">
                                            <option value="per_day_per_screen">{{ t('adbilling.model_screen_day') }}</option>
                                            <option value="per_thousand_impressions">{{ t('adbilling.model_thousand') }}</option>
                                        </select>
                                        <input v-model="cardRate" type="text" inputmode="decimal" placeholder="0.000" class="w-24 rounded-lg border-slate-300 text-xs" />
                                        <button class="rounded bg-teal-700 px-2 py-1 text-xs font-semibold text-white" :disabled="savingCard" @click="saveCard">{{ t('common.save') }}</button>
                                        <button class="rounded px-2 py-1 text-xs text-slate-500" @click="editingCard = null">{{ t('common.cancel') }}</button>
                                    </div>
                                </template>
                                <template v-else>
                                    <span v-if="row.pricing_model" class="text-slate-700">
                                        {{ row.pricing_model === 'per_day_per_screen' ? t('adbilling.model_screen_day') : t('adbilling.model_thousand') }}
                                        · {{ row.rate }} OMR
                                    </span>
                                    <span v-else class="text-amber-600">{{ t('adbilling.no_card') }}</span>
                                    <button v-if="canManage" class="ms-2 text-xs font-semibold text-teal-700 hover:underline" @click="openCardEditor(row)">{{ t('common.edit') }}</button>
                                </template>
                            </td>
                            <td class="px-4 py-2 text-end font-semibold tabular-nums">
                                {{ row.estimated_amount ?? '—' }}
                            </td>
                            <td class="px-4 py-2 text-end">
                                <span v-if="row.has_overlapping_invoice" class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500">{{ t('adbilling.already_billed') }}</span>
                                <button
                                    v-else-if="canManage && row.estimated_amount !== null"
                                    class="rounded-lg bg-teal-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-teal-800"
                                    :disabled="issuing === row.advertiser_id"
                                    @click="issue(row)"
                                >{{ t('adbilling.issue') }}</button>
                            </td>
                        </tr>
                        <tr v-if="!loading && pending.length === 0">
                            <td colspan="6" class="px-4 py-8 text-center text-slate-500">{{ t('adbilling.nothing_delivered') }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- Invoices -->
            <section class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="flex items-center justify-between border-b border-slate-100 p-4">
                    <h2 class="font-semibold text-slate-900">{{ t('adbilling.invoices') }}</h2>
                    <select v-model="statusFilter" class="rounded-lg border-slate-300 text-sm" @change="loadInvoices(1)">
                        <option value="">{{ t('adbilling.all_statuses') }}</option>
                        <option value="issued">{{ t('adbilling.status_issued') }}</option>
                        <option value="paid">{{ t('adbilling.status_paid') }}</option>
                        <option value="void">{{ t('adbilling.status_void') }}</option>
                    </select>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-2"></th>
                            <th class="px-4 py-2 text-start">{{ t('adbilling.advertiser') }}</th>
                            <th class="px-4 py-2 text-start">{{ t('adbilling.period') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('adbilling.plays') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('adbilling.screen_days') }}</th>
                            <th class="px-4 py-2 text-end">{{ t('adbilling.amount') }}</th>
                            <th class="px-4 py-2 text-center">{{ t('adbilling.status') }}</th>
                            <th class="px-4 py-2 text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="inv in invoices" :key="inv.uuid">
                            <tr class="border-b border-slate-100">
                                <td class="px-2 py-2">
                                    <button class="text-slate-400 hover:text-slate-700" @click="toggleLines(inv)">
                                        <component :is="openLines.has(inv.uuid) ? ChevronDown : ChevronRight" class="h-4 w-4" />
                                    </button>
                                </td>
                                <td class="px-4 py-2 font-medium text-slate-900">{{ inv.brand_name || inv.advertiser_name || `#${inv.advertiser_id}` }}</td>
                                <td class="px-4 py-2 tabular-nums text-slate-600">{{ day(inv.period_from) }} → {{ day(inv.period_to) }}</td>
                                <td class="px-4 py-2 text-end tabular-nums">{{ inv.impressions_count }}</td>
                                <td class="px-4 py-2 text-end tabular-nums">{{ inv.screen_days }}</td>
                                <td class="px-4 py-2 text-end font-semibold tabular-nums">{{ inv.amount }}</td>
                                <td class="px-4 py-2 text-center">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                        :class="{
                                            'bg-amber-100 text-amber-800': inv.status === 'issued',
                                            'bg-emerald-100 text-emerald-800': inv.status === 'paid',
                                            'bg-slate-100 text-slate-500': inv.status === 'void',
                                        }"
                                    >{{ t(`adbilling.status_${inv.status}`) }}</span>
                                </td>
                                <td class="px-4 py-2 text-end">
                                    <div v-if="canManage && inv.status === 'issued'" class="flex justify-end gap-2">
                                        <button class="inline-flex items-center gap-1 rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-emerald-700" @click="markPaid(inv)">
                                            <CheckCircle2 class="h-3.5 w-3.5" />{{ t('adbilling.mark_paid') }}
                                        </button>
                                        <button class="inline-flex items-center gap-1 rounded-lg border border-rose-200 px-2.5 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50" @click="voidInv(inv)">
                                            <Ban class="h-3.5 w-3.5" />{{ t('adbilling.void') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="openLines.has(inv.uuid)" class="border-b border-slate-100 bg-slate-50/60">
                                <td></td>
                                <td colspan="7" class="px-4 py-3">
                                    <table class="w-full text-xs">
                                        <thead class="text-slate-500">
                                            <tr>
                                                <th class="py-1 text-start">{{ t('adbilling.branch') }}</th>
                                                <th class="py-1 text-end">{{ t('adbilling.plays') }}</th>
                                                <th class="py-1 text-end">{{ t('adbilling.screen_days') }}</th>
                                                <th class="py-1 text-end">{{ t('adbilling.play_seconds') }}</th>
                                                <th class="py-1 text-end">{{ t('adbilling.amount') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="line in linesByInvoice[inv.uuid]" :key="line.branch_id ?? -1">
                                                <td class="py-1">{{ line.branch_name }}</td>
                                                <td class="py-1 text-end tabular-nums">{{ line.plays }}</td>
                                                <td class="py-1 text-end tabular-nums">{{ line.screen_days }}</td>
                                                <td class="py-1 text-end tabular-nums">{{ line.play_seconds }}</td>
                                                <td class="py-1 text-end tabular-nums">{{ line.amount }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="invoices.length === 0">
                            <td colspan="8" class="px-4 py-8 text-center text-slate-500">{{ t('adbilling.no_invoices') }}</td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="invoiceLastPage > 1" class="flex items-center justify-end gap-3 border-t border-slate-100 p-3 text-sm">
                    <button class="rounded-lg border border-slate-200 px-3 py-1 disabled:opacity-40" :disabled="invoicePage <= 1" @click="loadInvoices(invoicePage - 1)">‹</button>
                    <span class="tabular-nums text-slate-500">{{ invoicePage }} / {{ invoiceLastPage }}</span>
                    <button class="rounded-lg border border-slate-200 px-3 py-1 disabled:opacity-40" :disabled="invoicePage >= invoiceLastPage" @click="loadInvoices(invoicePage + 1)">›</button>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
