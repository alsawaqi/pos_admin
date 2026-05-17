<script setup lang="ts">
import { Building2, Plus, Search } from 'lucide-vue-next';
import { onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import StatusPill from '@/Components/Admin/StatusPill.vue';
import { usePermissions } from '@/composables/usePermissions';
import {
    listMerchants,
    type CompanyStatus,
    type MerchantListItem,
    type PaginationMeta,
} from '@/lib/api/merchants';
import { PlatformPermission } from '@/lib/permissions';

const { t } = useI18n();
const { can } = usePermissions();

const merchants = ref<MerchantListItem[]>([]);
const meta = ref<PaginationMeta | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const search = ref('');
const status = ref<CompanyStatus | ''>('');
const page = ref(1);

const statusOptions: { value: CompanyStatus; label: string; tone: string }[] = [
    { value: 'onboarding', label: 'Onboarding', tone: 'amber' },
    { value: 'active', label: 'Active', tone: 'green' },
    { value: 'suspended', label: 'Suspended', tone: 'rose' },
    { value: 'inactive', label: 'Inactive', tone: 'slate' },
];

function statusTone(value: CompanyStatus | null): string {
    return statusOptions.find((s) => s.value === value)?.tone ?? 'slate';
}

function statusLabel(value: CompanyStatus | null): string {
    return statusOptions.find((s) => s.value === value)?.label ?? '—';
}

async function fetchPage(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const response = await listMerchants({
            page: page.value,
            search: search.value || undefined,
            status: status.value || undefined,
        });
        merchants.value = response.data;
        meta.value = response.meta;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load merchants';
    } finally {
        loading.value = false;
    }
}

let debounceTimer: number | null = null;

watch([search, status], () => {
    if (debounceTimer) {
        window.clearTimeout(debounceTimer);
    }
    debounceTimer = window.setTimeout(() => {
        page.value = 1;
        void fetchPage();
    }, 250);
});

onMounted(() => void fetchPage());
</script>

<template>
    <AdminLayout>
        <section class="space-y-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                        {{ t('merchants.section_label') }}
                    </p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">
                        {{ t('merchants.list_title') }}
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                        {{ t('merchants.list_subtitle') }}
                    </p>
                </div>

                <RouterLink
                    v-if="can(PlatformPermission.MerchantsCreate)"
                    to="/admin/merchants/new"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800 hover:shadow-xl"
                >
                    <Plus class="size-4" />
                    {{ t('merchants.create_button') }}
                </RouterLink>
            </div>

            <div class="grid gap-3 sm:grid-cols-[1fr_auto]">
                <label class="flex min-w-0 items-center gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3 text-slate-500 shadow-sm">
                    <Search class="size-5 shrink-0" />
                    <input
                        v-model="search"
                        type="search"
                        class="w-full bg-transparent text-sm font-medium text-slate-950 outline-none placeholder:text-slate-400"
                        :placeholder="t('merchants.search_placeholder')"
                    >
                </label>

                <select
                    v-model="status"
                    class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                >
                    <option value="">{{ t('merchants.filter_all_statuses') }}</option>
                    <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">
                        {{ opt.label }}
                    </option>
                </select>
            </div>

            <div
                v-if="error"
                class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700"
            >
                {{ error }}
            </div>

            <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <div v-if="loading" class="p-10 text-center text-sm font-medium text-slate-500">
                    {{ t('common.loading') }}
                </div>

                <div v-else-if="merchants.length === 0" class="flex flex-col items-center gap-3 p-12 text-center text-slate-500">
                    <Building2 class="size-10 text-slate-300" />
                    <p class="text-sm font-semibold">{{ t('merchants.empty_state') }}</p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.table.name') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.table.cr_number') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.table.contact') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.table.branches') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.table.devices') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.table.status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="merchant in merchants" :key="merchant.id" class="transition hover:bg-slate-50">
                                <td class="px-5 py-4">
                                    <RouterLink
                                        :to="`/admin/merchants/${merchant.uuid}`"
                                        class="block text-sm font-semibold text-slate-950 hover:text-teal-700"
                                    >
                                        {{ merchant.name }}
                                    </RouterLink>
                                    <span v-if="merchant.name_ar" class="mt-1 block text-xs text-slate-500" dir="rtl">
                                        {{ merchant.name_ar }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-sm font-medium text-slate-700">{{ merchant.cr_number ?? '—' }}</td>
                                <td class="px-5 py-4 text-sm text-slate-600">
                                    <span class="block">{{ merchant.contact.name ?? '—' }}</span>
                                    <span class="block text-xs text-slate-400">{{ merchant.contact.email ?? merchant.contact.phone ?? '' }}</span>
                                </td>
                                <td class="px-5 py-4 text-sm font-medium text-slate-800">{{ merchant.branches_count ?? 0 }}</td>
                                <td class="px-5 py-4 text-sm font-medium text-slate-800">{{ merchant.devices_count ?? 0 }}</td>
                                <td class="px-5 py-4">
                                    <StatusPill :label="statusLabel(merchant.status)" :tone="statusTone(merchant.status)" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="meta && meta.last_page > 1"
                    class="flex items-center justify-between gap-3 border-t border-slate-200 bg-slate-50/60 px-5 py-3 text-sm text-slate-600"
                >
                    <span>{{ t('common.pagination_summary', { from: meta.from ?? 0, to: meta.to ?? 0, total: meta.total }) }}</span>
                    <div class="flex gap-2">
                        <button
                            type="button"
                            class="rounded-lg border border-slate-200 bg-white px-3 py-2 font-semibold text-slate-700 disabled:opacity-50"
                            :disabled="page <= 1"
                            @click="page--; fetchPage()"
                        >
                            {{ t('common.previous') }}
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-slate-200 bg-white px-3 py-2 font-semibold text-slate-700 disabled:opacity-50"
                            :disabled="page >= meta.last_page"
                            @click="page++; fetchPage()"
                        >
                            {{ t('common.next') }}
                        </button>
                    </div>
                </div>
            </section>
        </section>
    </AdminLayout>
</template>
