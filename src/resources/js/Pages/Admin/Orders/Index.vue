<script setup lang="ts">
/**
 * Admin Sales / Orders -- platform-wide list of EVERY merchant's orders,
 * filterable by date, merchant, and status, with the period's order count
 * + total sales. reports.view gated (sidebar hides the entry otherwise;
 * the server enforces the same).
 */
import { ChevronLeft, ChevronRight, Search } from 'lucide-vue-next';
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { listAdminOrders, type AdminOrderRow } from '@/lib/api/orders';
import { listMerchants, type MerchantListItem, type PaginationMeta } from '@/lib/api/merchants';

const { t } = useI18n();

function todayIso(): string {
    return new Date().toISOString().slice(0, 10);
}

const rows = ref<AdminOrderRow[]>([]);
const meta = ref<PaginationMeta | null>(null);
const totals = ref<{ count: number; grand_total: string } | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
const merchants = ref<MerchantListItem[]>([]);

const companyUuid = ref('');
const status = ref('');
const fromDate = ref(todayIso());
const toDate = ref(todayIso());
const page = ref(1);

const statusOptions = ['open', 'paid', 'void'] as const;

async function fetchPage(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const res = await listAdminOrders({
            page: page.value,
            from: fromDate.value || undefined,
            to: toDate.value || undefined,
            company_uuid: companyUuid.value || undefined,
            status: status.value || undefined,
        });
        rows.value = res.data;
        meta.value = res.meta;
        totals.value = res.totals;
    } catch {
        error.value = t('orders.load_failed');
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

onMounted(async () => {
    try {
        const res = await listMerchants();
        merchants.value = res.data;
    } catch {
        // dropdown stays empty -> "all merchants" still works
    }
    void fetchPage();
});

function shortId(uuid: string): string {
    return uuid.slice(0, 8).toUpperCase();
}

function formatDateTime(iso: string | null): string {
    if (!iso) return '—';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString();
}

function statusClass(s: string | null): string {
    switch (s) {
        case 'paid': return 'bg-emerald-100 text-emerald-700';
        case 'open': return 'bg-amber-100 text-amber-700';
        case 'void': return 'bg-rose-100 text-rose-700';
        default: return 'bg-slate-100 text-slate-600';
    }
}
</script>

<template>
    <AdminLayout>
        <div class="max-w-7xl">
            <header class="mb-5 flex flex-col gap-1.5">
                <span class="text-xs font-semibold uppercase tracking-[0.15em] text-indigo-600">{{ t('orders.section_label') }}</span>
                <h1 class="text-3xl font-bold text-slate-950">{{ t('orders.title') }}</h1>
                <p class="max-w-3xl text-sm text-slate-600">{{ t('orders.subtitle') }}</p>
            </header>

            <div class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
                    <span class="text-slate-500">{{ t('orders.filters.date_from') }}</span>
                    <input type="date" v-model="fromDate" class="w-40 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" />
                </label>
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
                    <span class="text-slate-500">{{ t('orders.filters.date_to') }}</span>
                    <input type="date" v-model="toDate" class="w-40 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" />
                </label>
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
                    <span class="text-slate-500">{{ t('orders.filters.merchant') }}</span>
                    <select v-model="companyUuid" class="w-52 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                        <option value="">{{ t('orders.filters.merchant_all') }}</option>
                        <option v-for="m in merchants" :key="m.uuid" :value="m.uuid">{{ m.name }}</option>
                    </select>
                </label>
                <label class="flex flex-col gap-1 text-xs font-semibold text-slate-700">
                    <span class="text-slate-500">{{ t('orders.filters.status') }}</span>
                    <select v-model="status" class="w-36 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                        <option value="">{{ t('orders.filters.status_all') }}</option>
                        <option v-for="s in statusOptions" :key="s" :value="s">{{ t(`orders.statuses.${s}`) }}</option>
                    </select>
                </label>
                <button
                    type="button"
                    class="ms-auto inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:opacity-50"
                    :disabled="loading"
                    @click="applyFilters"
                >
                    <Search class="size-4" />
                    {{ loading ? t('orders.filters.running') : t('orders.filters.run') }}
                </button>
            </div>

            <div v-if="totals" class="mb-5 grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('orders.totals.count') }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-slate-950">{{ totals.count }}</p>
                </div>
                <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-4 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">{{ t('orders.totals.grand_total') }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-indigo-900">{{ totals.grand_total }} <span class="text-sm font-medium">OMR</span></p>
                </div>
            </div>

            <div v-if="error" class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">{{ error }}</div>

            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm">
                <table v-if="rows.length" class="w-full text-sm">
                    <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-2 text-start">{{ t('orders.columns.time') }}</th>
                            <th class="px-5 py-2 text-start">{{ t('orders.columns.order') }}</th>
                            <th class="px-5 py-2 text-start">{{ t('orders.columns.merchant') }}</th>
                            <th class="px-5 py-2 text-start">{{ t('orders.columns.branch') }}</th>
                            <th class="px-5 py-2 text-start">{{ t('orders.columns.type') }}</th>
                            <th class="px-5 py-2 text-start">{{ t('orders.columns.status') }}</th>
                            <th class="px-5 py-2 text-end">{{ t('orders.columns.total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" class="border-b border-slate-100 last:border-0">
                            <td class="px-5 py-2 text-xs tabular-nums text-slate-600">{{ formatDateTime(row.opened_at) }}</td>
                            <td class="px-5 py-2 font-mono text-xs font-semibold text-slate-900">{{ shortId(row.uuid) }}</td>
                            <td class="px-5 py-2 font-medium text-slate-800">{{ row.company?.name ?? '—' }}</td>
                            <td class="px-5 py-2 text-slate-700">{{ row.branch?.name ?? '—' }}</td>
                            <td class="px-5 py-2 text-slate-700">{{ row.order_type ? t(`orders.types.${row.order_type}`) : '—' }}</td>
                            <td class="px-5 py-2">
                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="statusClass(row.status)">{{ row.status ? t(`orders.statuses.${row.status}`) : '—' }}</span>
                            </td>
                            <td class="px-5 py-2 text-end font-semibold tabular-nums text-slate-900">{{ row.grand_total }}</td>
                        </tr>
                    </tbody>
                </table>

                <div v-else-if="!loading" class="p-8 text-center text-sm text-slate-500">{{ t('orders.no_rows') }}</div>
                <div v-else class="p-8 text-center text-sm text-slate-500">{{ t('orders.filters.running') }}</div>

                <div v-if="meta" class="flex items-center justify-between border-t border-slate-200 px-5 py-3 text-xs text-slate-600">
                    <div>{{ t('orders.pagination', { page: meta.current_page, last: meta.last_page, total: meta.total }) }}</div>
                    <div class="flex items-center gap-2">
                        <button type="button" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-semibold disabled:opacity-50" :disabled="meta.current_page <= 1 || loading" @click="goPage(meta.current_page - 1)">
                            <ChevronLeft class="size-3.5" /> {{ t('orders.prev') }}
                        </button>
                        <button type="button" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-semibold disabled:opacity-50" :disabled="meta.current_page >= meta.last_page || loading" @click="goPage(meta.current_page + 1)">
                            {{ t('orders.next') }} <ChevronRight class="size-3.5" />
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
