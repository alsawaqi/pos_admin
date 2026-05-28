<script setup lang="ts">
import { ClipboardList, Pencil, Plus, Search, Trash2 } from 'lucide-vue-next';
import { onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import ConfirmDialog from '@/Components/Admin/ConfirmDialog.vue';
import StatusPill, { type StatusTone } from '@/Components/Admin/StatusPill.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    deleteBranch,
    listBranches,
    type BranchListItem,
    type BranchStatus,
} from '@/lib/api/branches';
import { listMerchants, type MerchantListItem, type PaginationMeta } from '@/lib/api/merchants';
import { PlatformPermission } from '@/lib/permissions';

const { t } = useI18n();
const { can } = usePermissions();

const branches = ref<BranchListItem[]>([]);
const meta = ref<PaginationMeta | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const search = ref('');
const status = ref<BranchStatus | ''>('');
const companyId = ref<number | ''>('');
const merchants = ref<MerchantListItem[]>([]);
const page = ref(1);

const statusOptions: { value: BranchStatus; tone: StatusTone }[] = [
    { value: 'active', tone: 'green' },
    { value: 'inactive', tone: 'slate' },
];

function statusTone(value: BranchStatus | null): StatusTone {
    return statusOptions.find((s) => s.value === value)?.tone ?? 'slate';
}

function statusLabel(value: BranchStatus | null): string {
    if (!value) {
        return '—';
    }

    return t(`branches.status_options.${value}`);
}

async function fetchPage(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const response = await listBranches({
            page: page.value,
            search: search.value || undefined,
            status: status.value || undefined,
            company_id: companyId.value || undefined,
        });
        branches.value = response.data;
        meta.value = response.meta;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load branches';
    } finally {
        loading.value = false;
    }
}

// ---- Delete flow --------------------------------------------------
const deleteTarget = ref<BranchListItem | null>(null);
const deleting = ref(false);
const deleteError = ref<string | null>(null);

function openDelete(row: BranchListItem): void {
    deleteTarget.value = row;
    deleteError.value = null;
}

async function confirmDelete(): Promise<void> {
    if (!deleteTarget.value) {
        return;
    }
    deleting.value = true;
    deleteError.value = null;
    try {
        await deleteBranch(deleteTarget.value.uuid);
        deleteTarget.value = null;
        await fetchPage();
    } catch (err) {
        // 409 ("still has active devices") surfaces here.
        if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            deleteError.value = String((err.payload as { message?: unknown }).message ?? 'Delete failed');
        } else {
            deleteError.value = err instanceof Error ? err.message : 'Delete failed';
        }
    } finally {
        deleting.value = false;
    }
}

async function loadMerchants(): Promise<void> {
    try {
        // Filter dropdown only needs name + id; cap to first 100 for the picker.
        const response = await listMerchants({ per_page: 100 });
        merchants.value = response.data;
    } catch {
        merchants.value = [];
    }
}

let debounceTimer: number | null = null;

watch([search, status, companyId], () => {
    if (debounceTimer) {
        window.clearTimeout(debounceTimer);
    }
    debounceTimer = window.setTimeout(() => {
        page.value = 1;
        void fetchPage();
    }, 250);
});

onMounted(() => {
    void fetchPage();
    void loadMerchants();
});
</script>

<template>
    <AdminLayout>
        <section class="space-y-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                        {{ t('branches.section_label') }}
                    </p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">
                        {{ t('branches.list_title') }}
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                        {{ t('branches.list_subtitle') }}
                    </p>
                </div>

                <RouterLink
                    v-if="can(PlatformPermission.BranchesCreate)"
                    to="/admin/branches/new"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800 hover:shadow-xl"
                >
                    <Plus class="size-4" />
                    {{ t('branches.create_button') }}
                </RouterLink>
            </div>

            <div class="grid gap-3 sm:grid-cols-[1fr_auto_auto]">
                <label class="flex min-w-0 items-center gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3 text-slate-500 shadow-sm">
                    <Search class="size-5 shrink-0" />
                    <input
                        v-model="search"
                        type="search"
                        class="w-full bg-transparent text-sm font-medium text-slate-950 outline-none placeholder:text-slate-400"
                        :placeholder="t('branches.search_placeholder')"
                    >
                </label>

                <select
                    v-model="companyId"
                    class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                >
                    <option value="">{{ t('branches.filter_all_companies') }}</option>
                    <option v-for="m in merchants" :key="m.id" :value="m.id">{{ m.name }}</option>
                </select>

                <select
                    v-model="status"
                    class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                >
                    <option value="">{{ t('branches.filter_all_statuses') }}</option>
                    <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">
                        {{ t(`branches.status_options.${opt.value}`) }}
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

                <div v-else-if="branches.length === 0" class="flex flex-col items-center gap-3 p-12 text-center text-slate-500">
                    <ClipboardList class="size-10 text-slate-300" />
                    <p class="text-sm font-semibold">{{ t('branches.empty_state') }}</p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('branches.table.name') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('branches.table.company') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('branches.table.code') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('branches.table.manager') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('branches.table.geofence') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('branches.table.devices') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('branches.table.status') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="branch in branches" :key="branch.id" class="transition hover:bg-slate-50">
                                <td class="px-5 py-4">
                                    <RouterLink
                                        :to="`/admin/branches/${branch.uuid}`"
                                        class="block text-sm font-semibold text-slate-950 hover:text-teal-700"
                                    >
                                        {{ branch.name }}
                                    </RouterLink>
                                    <span v-if="branch.name_ar" class="mt-1 block text-xs text-slate-500" dir="rtl">
                                        {{ branch.name_ar }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-sm font-medium text-slate-700">{{ branch.company?.name ?? '—' }}</td>
                                <td class="px-5 py-4 text-sm font-medium text-slate-700">{{ branch.code ?? '—' }}</td>
                                <td class="px-5 py-4 text-sm text-slate-600">
                                    <span class="block">{{ branch.manager_name ?? '—' }}</span>
                                    <span class="block text-xs text-slate-400">{{ branch.phone ?? '' }}</span>
                                </td>
                                <td class="px-5 py-4 text-sm font-medium text-slate-800">{{ branch.geofence_radius_m }} m</td>
                                <td class="px-5 py-4 text-sm font-medium text-slate-800">{{ branch.devices_count ?? 0 }}</td>
                                <td class="px-5 py-4">
                                    <StatusPill :label="statusLabel(branch.status)" :tone="statusTone(branch.status)" />
                                </td>
                                <td class="px-5 py-4 text-end">
                                    <div class="inline-flex items-center gap-2">
                                        <RouterLink
                                            v-if="can(PlatformPermission.BranchesUpdate)"
                                            :to="`/admin/branches/${branch.uuid}`"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                                        >
                                            <Pencil class="size-3.5" />
                                            {{ t('common.edit') }}
                                        </RouterLink>
                                        <button
                                            v-if="can(PlatformPermission.BranchesDelete)"
                                            type="button"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-50"
                                            @click="openDelete(branch)"
                                        >
                                            <Trash2 class="size-3.5" />
                                            {{ t('common.delete') }}
                                        </button>
                                    </div>
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

        <ConfirmDialog
            v-if="deleteTarget"
            :title="t('branches.delete.title')"
            :message="t('branches.delete.message', { name: deleteTarget.name })"
            :confirm-label="t('common.delete')"
            :loading="deleting"
            :error="deleteError"
            @confirm="confirmDelete"
            @cancel="deleteTarget = null"
        />
    </AdminLayout>
</template>
