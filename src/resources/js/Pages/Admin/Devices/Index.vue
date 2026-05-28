<script setup lang="ts">
/**
 * Devices list / fleet view — admin's catch-all surface for finding,
 * filtering, and drilling into any device on the platform.
 *
 * Blueprint refs:
 *   §4.4.1 — Devices List Page columns + filters
 *   §4.8   — Fleet KPIs that link out from here
 *
 * Filters supported (debounced 250 ms before re-fetch):
 *   - Free-text search (matches serial, kiosk_id, name, label)
 *   - Device type (Fixed POS / Handheld / Customer Tablet)
 *   - Status (Registered / Assigned / Active / Inactive / Blocked)
 *   - Company (loaded from /merchants for the dropdown)
 *   - "Show unassigned only" toggle
 *
 * Permission gating:
 *   - Page itself is reachable when the user has DevicesView
 *     (enforced both server-side by DevicePolicy and client-side by
 *     the sidebar filter in AdminLayout).
 *   - "Register device" button is shown only when DevicesRegister is
 *     granted.
 */

import { MonitorSmartphone, Plus, Power, Search } from 'lucide-vue-next';
import { onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import ConfirmDialog from '@/Components/Admin/ConfirmDialog.vue';
import StatusPill, { type StatusTone } from '@/Components/Admin/StatusPill.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    decommissionDevice,
    listDevices,
    type DeviceListItem,
    type DeviceStatus,
    type DeviceType,
} from '@/lib/api/devices';
import { listMerchants, type MerchantListItem, type PaginationMeta } from '@/lib/api/merchants';
import { PlatformPermission } from '@/lib/permissions';

const { t } = useI18n();
const { can } = usePermissions();

// Reactive state. We split the table data, pagination meta, loading
// flag, error string, and individual filter refs so the watcher only
// re-fetches when one of the filters actually changes — not on every
// page render.
const devices = ref<DeviceListItem[]>([]);
const meta = ref<PaginationMeta | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

const search = ref('');
const deviceType = ref<DeviceType | ''>('');
const status = ref<DeviceStatus | ''>('');
const companyId = ref<number | ''>('');
const unassignedOnly = ref(false);
const page = ref(1);

const merchants = ref<MerchantListItem[]>([]);

// Catalogues for the dropdowns + status pill colour mapping.
// Tones must match StatusPill's StatusTone union.
const typeOptions: DeviceType[] = ['fixed_pos', 'handheld', 'customer_tablet'];
const statusOptions: { value: DeviceStatus; tone: StatusTone }[] = [
    { value: 'registered', tone: 'slate' },
    { value: 'assigned', tone: 'sky' },
    { value: 'active', tone: 'green' },
    { value: 'inactive', tone: 'amber' },
    { value: 'blocked', tone: 'rose' },
];

function statusTone(value: DeviceStatus | null): StatusTone {
    return statusOptions.find((s) => s.value === value)?.tone ?? 'slate';
}

function statusLabel(value: DeviceStatus | null): string {
    return value ? t(`devices.status_options.${value}`) : '—';
}

function typeLabel(value: DeviceType | null): string {
    return value ? t(`devices.type_options.${value}`) : '—';
}

/**
 * Pull the current page from the API, applying every active filter.
 * Empty strings / unset filters are stripped (`|| undefined`) so they
 * don't appear in the query string at all.
 */
async function fetchPage(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const response = await listDevices({
            page: page.value,
            search: search.value || undefined,
            device_type: deviceType.value || undefined,
            status: status.value || undefined,
            company_id: companyId.value || undefined,
            unassigned: unassignedOnly.value || undefined,
        });
        devices.value = response.data;
        meta.value = response.meta;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load devices';
    } finally {
        loading.value = false;
    }
}

// Populates the "company" filter dropdown. Tries to load 100 merchants
// (the cap) — enough for the pilot. If it fails silently, the filter
// simply stays empty rather than blowing up the page.
async function loadMerchants(): Promise<void> {
    try {
        const response = await listMerchants({ per_page: 100 });
        merchants.value = response.data;
    } catch {
        merchants.value = [];
    }
}

// Debounce so typing in the search input doesn't fire a request per
// keystroke. Also resets to page 1 on any filter change because the
// total count might have shrunk below the current page.
let debounceTimer: number | null = null;
watch([search, deviceType, status, companyId, unassignedOnly], () => {
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

// ---- Decommission flow --------------------------------------------
const decommissionTarget = ref<DeviceListItem | null>(null);
const decommissioning = ref(false);
const decommissionError = ref<string | null>(null);

function openDecommission(row: DeviceListItem): void {
    decommissionTarget.value = row;
    decommissionError.value = null;
}

async function confirmDecommission(): Promise<void> {
    if (!decommissionTarget.value) {
        return;
    }
    decommissioning.value = true;
    decommissionError.value = null;
    try {
        await decommissionDevice(decommissionTarget.value.uuid);
        decommissionTarget.value = null;
        await fetchPage();
    } catch (err) {
        if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            decommissionError.value = String((err.payload as { message?: unknown }).message ?? 'Decommission failed');
        } else {
            decommissionError.value = err instanceof Error ? err.message : 'Decommission failed';
        }
    } finally {
        decommissioning.value = false;
    }
}
</script>

<template>
    <AdminLayout>
        <section class="space-y-6">
            <!-- Header: section label + title + subtitle, plus the
                 Register button gated on the DevicesRegister permission. -->
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                        {{ t('devices.section_label') }}
                    </p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">
                        {{ t('devices.list_title') }}
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                        {{ t('devices.list_subtitle') }}
                    </p>
                </div>

                <RouterLink
                    v-if="can(PlatformPermission.DevicesRegister)"
                    to="/admin/devices/new"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800 hover:shadow-xl"
                >
                    <Plus class="size-4" />
                    {{ t('devices.create_button') }}
                </RouterLink>
            </div>

            <!-- Filter strip. The grid switches to a single column on
                 mobile and stretches the search to fill the row above
                 1024px. -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_auto_auto_auto_auto]">
                <label class="flex min-w-0 items-center gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3 text-slate-500 shadow-sm">
                    <Search class="size-5 shrink-0" />
                    <input
                        v-model="search"
                        type="search"
                        class="w-full bg-transparent text-sm font-medium text-slate-950 outline-none placeholder:text-slate-400"
                        :placeholder="t('devices.search_placeholder')"
                    >
                </label>

                <select
                    v-model="deviceType"
                    class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                >
                    <option value="">{{ t('devices.filter_all_types') }}</option>
                    <option v-for="opt in typeOptions" :key="opt" :value="opt">
                        {{ t(`devices.type_options.${opt}`) }}
                    </option>
                </select>

                <select
                    v-model="status"
                    class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                >
                    <option value="">{{ t('devices.filter_all_statuses') }}</option>
                    <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">
                        {{ t(`devices.status_options.${opt.value}`) }}
                    </option>
                </select>

                <select
                    v-model="companyId"
                    class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                >
                    <option value="">{{ t('devices.filter_all_companies') }}</option>
                    <option v-for="m in merchants" :key="m.id" :value="m.id">{{ m.name }}</option>
                </select>

                <!-- "Show unassigned only" — useful when the admin is
                     about to assign a freshly registered device. -->
                <label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm">
                    <input
                        v-model="unassignedOnly"
                        type="checkbox"
                        class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                    >
                    {{ t('devices.filter_unassigned') }}
                </label>
            </div>

            <div v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                {{ error }}
            </div>

            <!-- Data table — three states: loading skeleton, empty
                 state with an icon, or the actual table. -->
            <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <div v-if="loading" class="p-10 text-center text-sm font-medium text-slate-500">
                    {{ t('common.loading') }}
                </div>

                <div v-else-if="devices.length === 0" class="flex flex-col items-center gap-3 p-12 text-center text-slate-500">
                    <MonitorSmartphone class="size-10 text-slate-300" />
                    <p class="text-sm font-semibold">{{ t('devices.empty_state') }}</p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('devices.table.label') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('devices.table.kiosk_id') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('devices.table.type') }}</th>
                                <!-- Bank column (Sprint 1.5 follow-up).
                                     Prefer short_name when present, fall
                                     back to full name; "—" when the
                                     device pre-dates the bank binding. -->
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('devices.table.bank') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('devices.table.assignment') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('devices.table.last_seen') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('devices.table.battery') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('devices.table.status') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="device in devices" :key="device.id" class="transition hover:bg-slate-50">
                                <!-- Primary identifier column: label
                                     (or name) clickable through to the
                                     detail page, with the serial as a
                                     subtitle for support reference. -->
                                <td class="px-5 py-4">
                                    <RouterLink
                                        :to="`/admin/devices/${device.uuid}`"
                                        class="block text-sm font-semibold text-slate-950 hover:text-teal-700"
                                    >
                                        {{ device.label ?? device.name ?? device.serial_number }}
                                    </RouterLink>
                                    <span class="mt-1 block text-xs text-slate-500">{{ device.serial_number }}</span>
                                </td>
                                <td class="px-5 py-4 text-sm font-mono text-slate-700">{{ device.kiosk_id ?? '—' }}</td>
                                <td class="px-5 py-4 text-sm font-medium text-slate-700">{{ typeLabel(device.device_type) }}</td>
                                <!-- Bank cell — short_name if available
                                     (snappier in a tight column), full
                                     name otherwise. The bank object is
                                     null when the device pre-dates the
                                     bank-binding migration. -->
                                <td class="px-5 py-4 text-sm font-medium text-slate-700">
                                    {{ device.bank?.short_name ?? device.bank?.name ?? '—' }}
                                </td>

                                <!-- Assignment column: company + branch
                                     names stacked, or a "—" if the
                                     device hasn't been placed yet. -->
                                <td class="px-5 py-4 text-sm text-slate-600">
                                    <template v-if="device.company">
                                        <span class="block font-medium text-slate-700">{{ device.company.name }}</span>
                                        <span class="block text-xs text-slate-400">{{ device.branch?.name ?? '—' }}</span>
                                    </template>
                                    <span v-else class="text-slate-400">{{ t('devices.unassigned_label') }}</span>
                                </td>

                                <!-- Heartbeat-driven columns. last_seen
                                     gets a friendly ISO timestamp; the
                                     dashboard widget converts these to
                                     "X minutes ago" — here we keep it
                                     raw for the support agent. -->
                                <td class="px-5 py-4 text-xs font-medium text-slate-500">{{ device.last_seen_at ?? '—' }}</td>
                                <td class="px-5 py-4 text-sm font-medium text-slate-800">
                                    {{ device.last_battery !== null ? `${device.last_battery}%` : '—' }}
                                </td>

                                <td class="px-5 py-4">
                                    <StatusPill :label="statusLabel(device.status)" :tone="statusTone(device.status)" />
                                </td>
                                <td class="px-5 py-4 text-end">
                                    <!-- Decommission removes the device
                                         from the active fleet (soft delete
                                         + status=Blocked). Only shown to
                                         roles with DevicesDecommission
                                         (Super Admin in the seeder). -->
                                    <button
                                        v-if="can(PlatformPermission.DevicesDecommission)"
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-50"
                                        @click="openDecommission(device)"
                                    >
                                        <Power class="size-3.5" />
                                        {{ t('devices.decommission') }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination footer. Only shown when there is more
                     than one page so a 5-row fleet doesn't get
                     pointless prev/next buttons. -->
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
            v-if="decommissionTarget"
            tone="danger"
            :title="t('devices.decommission_dialog.title')"
            :message="t('devices.decommission_dialog.message', { label: decommissionTarget.label ?? decommissionTarget.name ?? decommissionTarget.serial_number })"
            :confirm-label="t('devices.decommission')"
            :loading="decommissioning"
            :error="decommissionError"
            @confirm="confirmDecommission"
            @cancel="decommissionTarget = null"
        />
    </AdminLayout>
</template>
