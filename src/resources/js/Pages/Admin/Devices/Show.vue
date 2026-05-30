<script setup lang="ts">
/**
 * Device Detail page — blueprint §4.4.4 (Phase 2 scope only).
 *
 * Two tabs visible in this phase:
 *   - Overview   : identity, current assignment, heartbeat snapshot
 *                  (last GPS / battery / IP), all the basic "is this
 *                  device alive and where is it" data.
 *   - Assignment : history table of every (de)assignment, newest
 *                  first, with the company/branch and the admin who
 *                  triggered it.
 *
 * Two actions:
 *   - Assign   — opens a modal where the admin picks company →
 *                branch, with an optional geo-fence radius override.
 *                The same modal is used for initial assignment AND
 *                reassignment — the back-end Action closes any prior
 *                open history row before opening the new one.
 *   - Unassign — opens a modal with a required-reason textarea.
 *
 * Permission gating:
 *   - Page reachable when DevicesView is granted.
 *   - "Assign" button shows when DevicesAssign is granted.
 *   - "Unassign" button shows when DevicesUnassign is granted.
 *
 * Sync/Geo-fence/Health tabs land in Phase 3 once the scalefusion
 * adapter is wired up — until then those columns just live on the
 * Overview snapshot.
 */

import { ArrowLeft, Copy, History, KeyRound, MonitorSmartphone, X } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import ConfirmDialog from '@/Components/Admin/ConfirmDialog.vue';
import StatusPill, { type StatusTone } from '@/Components/Admin/StatusPill.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    assignDevice,
    decommissionDevice,
    getDevice,
    issueDeviceActivationToken,
    unassignDevice,
    type DeviceDetail,
    type DeviceStatus,
} from '@/lib/api/devices';
import { listBranches, type BranchListItem } from '@/lib/api/branches';
import { listMerchants, type MerchantListItem } from '@/lib/api/merchants';
import { PlatformPermission } from '@/lib/permissions';

const { t } = useI18n();
const { can } = usePermissions();
const route = useRoute();

// --- Page-level state ----------------------------------------------
const device = ref<DeviceDetail | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
// 'overview' or 'history'. Drives the tab strip + content swap.
const activeTab = ref<'overview' | 'history'>('overview');

// --- Assign modal state --------------------------------------------
const assignOpen = ref(false);
const assignSubmitting = ref(false);
const assignError = ref<string | null>(null);
const assignForm = reactive({
    company_id: '' as number | '',
    branch_id: '' as number | '',
    geofence_radius_m: '' as number | '',
});
// Loaded once per modal open so the dropdowns are populated.
const merchants = ref<MerchantListItem[]>([]);
const branches = ref<BranchListItem[]>([]);

// --- Unassign modal state ------------------------------------------
const unassignOpen = ref(false);
const unassignSubmitting = ref(false);
const unassignError = ref<string | null>(null);
const unassignReason = ref('');

// --- Derived UI helpers --------------------------------------------
function statusTone(value: DeviceStatus | null): StatusTone {
    if (value === 'active') return 'green';
    if (value === 'assigned') return 'sky';
    if (value === 'blocked') return 'rose';
    if (value === 'inactive') return 'amber';

    return 'slate';
}

const statusLabel = computed(() =>
    device.value?.status ? t(`devices.status_options.${device.value.status}`) : '—',
);

const typeLabel = computed(() =>
    device.value?.device_type ? t(`devices.type_options.${device.value.device_type}`) : '—',
);

// True when the device currently has a branch — controls which
// action button (Assign / Reassign + Unassign) renders.
const isAssigned = computed(() => device.value?.branch_id !== null && device.value?.branch_id !== undefined);

// --- Data loaders --------------------------------------------------
async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const uuid = (route.params.uuid as string | undefined) ?? '';
        const response = await getDevice(uuid);
        device.value = response.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load device';
    } finally {
        loading.value = false;
    }
}

// Populate the Merchants dropdown once the Assign modal opens.
async function loadMerchants(): Promise<void> {
    try {
        const response = await listMerchants({ per_page: 100 });
        merchants.value = response.data;
    } catch {
        merchants.value = [];
    }
}

// Whenever the chosen company changes, refresh the branches list so
// the second dropdown is correctly filtered.
async function loadBranches(): Promise<void> {
    if (!assignForm.company_id) {
        branches.value = [];

        return;
    }
    try {
        const response = await listBranches({ company_id: assignForm.company_id, per_page: 100 });
        branches.value = response.data;
    } catch {
        branches.value = [];
    }
}

watch(() => assignForm.company_id, () => {
    // Reset the branch selection when the company changes so we
    // don't accidentally submit a stale branch_id from another
    // company.
    assignForm.branch_id = '';
    void loadBranches();
});

// --- Assign flow ---------------------------------------------------
function openAssign(): void {
    assignError.value = null;
    // Pre-fill with current assignment so "Reassign" doesn't ask the
    // user to retype everything when they only want to move branches
    // within the same company.
    assignForm.company_id = device.value?.company_id ?? '';
    assignForm.branch_id = device.value?.branch_id ?? '';
    assignForm.geofence_radius_m = device.value?.branch?.geofence_radius_m ?? '';
    assignOpen.value = true;
    void loadMerchants();
    void loadBranches();
}

function closeAssign(): void {
    assignOpen.value = false;
    assignError.value = null;
}

async function submitAssign(): Promise<void> {
    if (!device.value) {
        return;
    }
    if (assignForm.company_id === '' || assignForm.branch_id === '') {
        assignError.value = t('devices.assign.required');

        return;
    }
    assignSubmitting.value = true;
    assignError.value = null;
    try {
        const response = await assignDevice(device.value.uuid, {
            company_id: assignForm.company_id as number,
            branch_id: assignForm.branch_id as number,
            // Only send the radius override when the user actually
            // typed a value — empty string means "inherit branch".
            ...(assignForm.geofence_radius_m !== '' ? { geofence_radius_m: assignForm.geofence_radius_m as number } : {}),
        });
        device.value = response.data;
        closeAssign();
        await load();
    } catch (err) {
        assignError.value = err instanceof Error ? err.message : 'Failed to assign device';
    } finally {
        assignSubmitting.value = false;
    }
}

// --- Unassign flow -------------------------------------------------
function openUnassign(): void {
    unassignReason.value = '';
    unassignError.value = null;
    unassignOpen.value = true;
}

function closeUnassign(): void {
    unassignOpen.value = false;
    unassignError.value = null;
}

async function submitUnassign(): Promise<void> {
    if (!device.value) {
        return;
    }
    unassignSubmitting.value = true;
    unassignError.value = null;
    try {
        const response = await unassignDevice(device.value.uuid, {
            reason: unassignReason.value || undefined,
        });
        device.value = response.data;
        closeUnassign();
        await load();
    } catch (err) {
        unassignError.value = err instanceof Error ? err.message : 'Failed to unassign device';
    } finally {
        unassignSubmitting.value = false;
    }
}

// --- Decommission flow ---------------------------------------------
// Permanent removal of the device. Closes any open assignment,
// flips status to Blocked, soft-deletes the row. After success
// we navigate back to the fleet list because the current detail
// page would 404 on next refresh.
const router = useRouter();
const decommissionOpen = ref(false);
const decommissioning = ref(false);
const decommissionError = ref<string | null>(null);

async function confirmDecommission(): Promise<void> {
    if (!device.value) {
        return;
    }
    decommissioning.value = true;
    decommissionError.value = null;
    try {
        await decommissionDevice(device.value.uuid);
        decommissionOpen.value = false;
        await router.push('/admin/devices');
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

// --- Activation-token flow (Lane A) -------------------------------
// Mint a one-shot code, show it ONCE in a modal with a copy
// button + countdown. After Close the plaintext is gone — the
// admin would have to mint another. The audit log records the
// mint event (without the plaintext).
const activationOpen = ref(false);
const activationBusy = ref(false);
const activationCode = ref<string | null>(null);
const activationExpiresIn = ref<number | null>(null);
const activationError = ref<string | null>(null);
const activationCopied = ref(false);

async function mintActivationCode(): Promise<void> {
    if (!device.value) return;
    activationOpen.value = true;
    activationBusy.value = true;
    activationCode.value = null;
    activationError.value = null;
    activationCopied.value = false;
    try {
        const response = await issueDeviceActivationToken(device.value.uuid);
        activationCode.value = response.activation_code;
        activationExpiresIn.value = response.expires_in_minutes;
    } catch (err) {
        if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            activationError.value = String((err.payload as { message?: unknown }).message ?? 'Mint failed');
        } else {
            activationError.value = err instanceof Error ? err.message : 'Mint failed';
        }
    } finally {
        activationBusy.value = false;
    }
}

async function copyActivationCode(): Promise<void> {
    if (!activationCode.value) return;
    try {
        await navigator.clipboard.writeText(activationCode.value);
        activationCopied.value = true;
        window.setTimeout(() => { activationCopied.value = false; }, 2000);
    } catch {
        const el = document.getElementById('device-activation-code-out');
        if (el instanceof HTMLInputElement) el.select();
    }
}

function closeActivationModal(): void {
    activationOpen.value = false;
    activationCode.value = null;
    activationExpiresIn.value = null;
    activationError.value = null;
}

// Refetch when the route's uuid changes (back/forward navigation
// between devices) and once on mount.
watch(() => route.params.uuid, () => void load());
onMounted(() => void load());
</script>

<template>
    <AdminLayout>
        <section class="space-y-6">
            <RouterLink to="/admin/devices" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-slate-950">
                <ArrowLeft class="size-4" />
                {{ t('devices.back_to_list') }}
            </RouterLink>

            <div v-if="loading" class="rounded-xl border border-slate-200 bg-white p-10 text-center text-sm font-medium text-slate-500 shadow-sm">
                {{ t('common.loading') }}
            </div>

            <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                {{ error }}
            </div>

            <template v-else-if="device">
                <!-- Header: device identity + status pill + action
                     buttons. The button shown depends on whether the
                     device currently has a branch. -->
                <header class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div class="flex items-start gap-4">
                        <div class="grid size-14 place-items-center rounded-xl bg-slate-950 text-white">
                            <MonitorSmartphone class="size-7" />
                        </div>
                        <div>
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                                {{ typeLabel }}
                            </p>
                            <h1 class="mt-1 text-3xl font-semibold tracking-tight text-slate-950">
                                {{ device.label ?? device.name ?? device.serial_number }}
                            </h1>
                            <p class="mt-1 text-sm text-slate-500 font-mono">
                                {{ device.serial_number }}
                                <span class="mx-2 text-slate-300">•</span>
                                {{ device.kiosk_id ?? t('devices.no_kiosk_id') }}
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <StatusPill :label="statusLabel" :tone="statusTone(device.status)" />

                        <button
                            v-if="can(PlatformPermission.DevicesAssign)"
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800"
                            @click="openAssign"
                        >
                            {{ isAssigned ? t('devices.actions.reassign') : t('devices.actions.assign') }}
                        </button>

                        <button
                            v-if="isAssigned && can(PlatformPermission.DevicesUnassign)"
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border border-rose-200 bg-white px-4 py-2.5 text-sm font-semibold text-rose-700 shadow-sm hover:bg-rose-50"
                            @click="openUnassign"
                        >
                            {{ t('devices.actions.unassign') }}
                        </button>

                        <!-- Lane A — mint a one-shot activation
                             code. Only meaningful once the device
                             is assigned to a branch; the backend
                             returns 409 otherwise so we gate the
                             button on isAssigned too. -->
                        <button
                            v-if="isAssigned && can(PlatformPermission.DevicesActivate)"
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border border-teal-300 bg-teal-50 px-4 py-2.5 text-sm font-semibold text-teal-700 shadow-sm hover:bg-teal-100"
                            @click="mintActivationCode"
                        >
                            <KeyRound class="size-4" />
                            {{ t('devices.actions.issue_activation_code') }}
                        </button>

                        <!-- Decommission — destructive, only for
                             DevicesDecommission holders. Removes the
                             device from the active fleet entirely. -->
                        <button
                            v-if="can(PlatformPermission.DevicesDecommission)"
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border border-rose-300 bg-rose-50 px-4 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-100"
                            @click="decommissionOpen = true"
                        >
                            {{ t('devices.decommission') }}
                        </button>
                    </div>
                </header>

                <!-- Tab strip — overview vs history. -->
                <div class="border-b border-slate-200">
                    <nav class="flex gap-1">
                        <button
                            type="button"
                            class="-mb-px border-b-2 px-4 py-3 text-sm font-semibold transition"
                            :class="activeTab === 'overview' ? 'border-teal-500 text-slate-950' : 'border-transparent text-slate-500 hover:text-slate-800'"
                            @click="activeTab = 'overview'"
                        >
                            {{ t('devices.tabs.overview') }}
                        </button>
                        <button
                            type="button"
                            class="-mb-px border-b-2 px-4 py-3 text-sm font-semibold transition"
                            :class="activeTab === 'history' ? 'border-teal-500 text-slate-950' : 'border-transparent text-slate-500 hover:text-slate-800'"
                            @click="activeTab = 'history'"
                        >
                            {{ t('devices.tabs.history') }}
                        </button>
                    </nav>
                </div>

                <!-- Overview tab content. Two-column grid on large
                     screens: identity + assignment on the left,
                     heartbeat snapshot on the right. -->
                <div v-if="activeTab === 'overview'" class="grid gap-6 lg:grid-cols-[2fr_1fr]">
                    <div class="space-y-6">
                        <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ t('devices.overview.identity') }}</h2>
                            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                                <div>
                                    <dt class="font-medium text-slate-500">{{ t('devices.fields.serial_number') }}</dt>
                                    <dd class="font-mono font-semibold text-slate-900">{{ device.serial_number }}</dd>
                                </div>
                                <div>
                                    <dt class="font-medium text-slate-500">{{ t('devices.fields.kiosk_id') }}</dt>
                                    <dd class="font-mono font-semibold text-slate-900">{{ device.kiosk_id ?? '—' }}</dd>
                                </div>
                                <!-- Bank-issued terminal id. Mono
                                     font so support can read it back
                                     to the bank easily. -->
                                <div>
                                    <dt class="font-medium text-slate-500">{{ t('devices.fields.terminal_id') }}</dt>
                                    <dd class="font-mono font-semibold text-slate-900">{{ device.terminal_id ?? '—' }}</dd>
                                </div>
                                <!-- Commission profile name (nested
                                     object preloaded by the controller).
                                     Falls back to "—" when the
                                     relation wasn't loaded. -->
                                <div>
                                    <dt class="font-medium text-slate-500">{{ t('devices.fields.commission_profile') }}</dt>
                                    <dd class="font-semibold text-slate-900">{{ device.commission_profile?.name ?? '—' }}</dd>
                                </div>
                                <!-- Acquiring bank — the second half
                                     of the (bank, terminal_id) routing
                                     key the reconciler uses. Display
                                     the SWIFT code as a sub-line when
                                     present so support can quote it. -->
                                <div>
                                    <dt class="font-medium text-slate-500">{{ t('devices.fields.bank') }}</dt>
                                    <dd class="font-semibold text-slate-900">
                                        {{ device.bank?.name ?? '—' }}
                                        <span v-if="device.bank?.swift_code" class="block text-xs font-mono font-medium text-slate-500">
                                            {{ device.bank.swift_code }}
                                        </span>
                                    </dd>
                                </div>
                                <div>
                                    <dt class="font-medium text-slate-500">{{ t('devices.fields.device_type') }}</dt>
                                    <dd class="font-semibold text-slate-900">{{ typeLabel }}</dd>
                                </div>
                                <div>
                                    <dt class="font-medium text-slate-500">{{ t('devices.fields.make') }}</dt>
                                    <!-- Sprint 1.4: device.make is the
                                         nested catalogue object (or
                                         null if the device's make_id
                                         FK was nulled out when the
                                         make was deleted). -->
                                    <dd class="font-semibold text-slate-900">{{ device.make?.name ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="font-medium text-slate-500">{{ t('devices.fields.model') }}</dt>
                                    <dd class="font-semibold text-slate-900">{{ device.model?.name ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="font-medium text-slate-500">{{ t('devices.fields.label') }}</dt>
                                    <dd class="font-semibold text-slate-900">{{ device.label ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="font-medium text-slate-500">{{ t('devices.fields.name') }}</dt>
                                    <dd class="font-semibold text-slate-900">{{ device.name ?? '—' }}</dd>
                                </div>
                            </dl>
                        </section>

                        <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ t('devices.overview.current_assignment') }}</h2>
                            <template v-if="device.company">
                                <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                                    <div>
                                        <dt class="font-medium text-slate-500">{{ t('devices.fields.company') }}</dt>
                                        <dd class="font-semibold text-slate-900">{{ device.company.name }}</dd>
                                    </div>
                                    <div>
                                        <dt class="font-medium text-slate-500">{{ t('devices.fields.branch') }}</dt>
                                        <dd class="font-semibold text-slate-900">{{ device.branch?.name ?? '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="font-medium text-slate-500">{{ t('devices.fields.geofence_radius_m') }}</dt>
                                        <dd class="font-semibold text-slate-900">{{ device.branch?.geofence_radius_m ?? '—' }} m</dd>
                                    </div>
                                    <div>
                                        <dt class="font-medium text-slate-500">{{ t('devices.fields.assigned_at') }}</dt>
                                        <dd class="font-semibold text-slate-900">{{ device.assigned_at ?? '—' }}</dd>
                                    </div>
                                </dl>
                            </template>
                            <p v-else class="mt-4 text-sm text-slate-500">{{ t('devices.overview.no_assignment') }}</p>
                        </section>
                    </div>

                    <aside class="space-y-6">
                        <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ t('devices.overview.heartbeat') }}</h2>
                            <dl class="mt-4 space-y-3 text-sm">
                                <div>
                                    <dt class="font-medium text-slate-500">{{ t('devices.fields.last_seen_at') }}</dt>
                                    <dd class="font-semibold text-slate-900">{{ device.last_seen_at ?? t('devices.never_seen') }}</dd>
                                </div>
                                <div>
                                    <dt class="font-medium text-slate-500">{{ t('devices.fields.last_battery') }}</dt>
                                    <dd class="font-semibold text-slate-900">{{ device.last_battery !== null ? `${device.last_battery}%` : '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="font-medium text-slate-500">{{ t('devices.fields.last_ip') }}</dt>
                                    <dd class="font-semibold text-slate-900 font-mono">{{ device.last_ip ?? '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="font-medium text-slate-500">{{ t('devices.fields.app_version') }}</dt>
                                    <dd class="font-semibold text-slate-900">{{ device.app_version ?? '—' }}</dd>
                                </div>
                            </dl>
                        </section>
                    </aside>
                </div>

                <!-- History tab content: scrollable timeline of every
                     past assignment. Currently-open assignment shows
                     a "current" pill. -->
                <div v-else-if="activeTab === 'history'" class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ t('devices.history.title') }}</h2>

                    <div v-if="!device.assignment_history || device.assignment_history.length === 0" class="mt-6 flex flex-col items-center gap-3 text-center text-sm text-slate-500">
                        <History class="size-8 text-slate-300" />
                        <p>{{ t('devices.history.empty') }}</p>
                    </div>

                    <ul v-else class="mt-6 space-y-4">
                        <li
                            v-for="entry in device.assignment_history"
                            :key="entry.id"
                            class="rounded-lg border border-slate-200 px-5 py-4 text-sm"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="font-semibold text-slate-900">
                                    {{ entry.company?.name ?? t('devices.history.unknown_company') }}
                                    <span class="mx-1 text-slate-300">/</span>
                                    {{ entry.branch?.name ?? t('devices.history.unknown_branch') }}
                                </p>
                                <span
                                    v-if="entry.unassigned_at === null"
                                    class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700"
                                >
                                    {{ t('devices.history.current') }}
                                </span>
                            </div>
                            <p class="mt-2 text-xs text-slate-500">
                                {{ t('devices.history.assigned_at', { at: entry.assigned_at ?? '—' }) }}
                                <template v-if="entry.unassigned_at">
                                    <span class="mx-2 text-slate-300">→</span>
                                    {{ t('devices.history.unassigned_at', { at: entry.unassigned_at }) }}
                                </template>
                            </p>
                            <p v-if="entry.unassign_reason" class="mt-2 text-xs text-slate-600">
                                {{ t('devices.history.reason') }}: {{ entry.unassign_reason }}
                            </p>
                        </li>
                    </ul>
                </div>
            </template>

            <!-- ASSIGN MODAL ------------------------------------------------
                 Renders only when assignOpen=true. Simple absolutely-
                 positioned overlay + centered card, no third-party
                 modal lib. -->
            <div
                v-if="assignOpen"
                class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/50 backdrop-blur-sm p-4"
                @click.self="closeAssign"
            >
                <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-slate-950">
                            {{ isAssigned ? t('devices.assign.title_reassign') : t('devices.assign.title') }}
                        </h2>
                        <button type="button" class="rounded-lg p-1 text-slate-500 hover:bg-slate-100" @click="closeAssign">
                            <X class="size-5" />
                        </button>
                    </div>
                    <p class="mt-2 text-sm text-slate-600">{{ t('devices.assign.subtitle') }}</p>

                    <div v-if="assignError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                        {{ assignError }}
                    </div>

                    <form class="mt-6 space-y-4" @submit.prevent="submitAssign">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('devices.fields.company') }}</span>
                            <select
                                v-model="assignForm.company_id"
                                required
                                class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                            >
                                <option value="">{{ t('devices.assign.select_company') }}</option>
                                <option v-for="m in merchants" :key="m.id" :value="m.id">{{ m.name }}</option>
                            </select>
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('devices.fields.branch') }}</span>
                            <select
                                v-model="assignForm.branch_id"
                                required
                                :disabled="!assignForm.company_id"
                                class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-50"
                            >
                                <option value="">{{ t('devices.assign.select_branch') }}</option>
                                <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                            </select>
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('devices.fields.geofence_radius_m') }}</span>
                            <input
                                v-model.number="assignForm.geofence_radius_m"
                                type="number"
                                min="100"
                                max="2000"
                                step="50"
                                class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                            >
                            <p class="mt-1 text-xs text-slate-500">{{ t('devices.assign.geofence_help') }}</p>
                        </label>

                        <div class="flex items-center justify-end gap-3 pt-2">
                            <button
                                type="button"
                                class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                                @click="closeAssign"
                            >
                                {{ t('devices.form.cancel') }}
                            </button>
                            <button
                                type="submit"
                                :disabled="assignSubmitting"
                                class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800 disabled:cursor-wait disabled:opacity-70"
                            >
                                {{ assignSubmitting ? t('devices.form.submitting') : t('devices.assign.submit') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- UNASSIGN MODAL ---------------------------------------------- -->
            <div
                v-if="unassignOpen"
                class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/50 backdrop-blur-sm p-4"
                @click.self="closeUnassign"
            >
                <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-slate-950">{{ t('devices.unassign.title') }}</h2>
                        <button type="button" class="rounded-lg p-1 text-slate-500 hover:bg-slate-100" @click="closeUnassign">
                            <X class="size-5" />
                        </button>
                    </div>
                    <p class="mt-2 text-sm text-slate-600">{{ t('devices.unassign.subtitle') }}</p>

                    <div v-if="unassignError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                        {{ unassignError }}
                    </div>

                    <form class="mt-6 space-y-4" @submit.prevent="submitUnassign">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('devices.unassign.reason_label') }}</span>
                            <textarea
                                v-model="unassignReason"
                                rows="3"
                                class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                                :placeholder="t('devices.unassign.reason_placeholder')"
                            />
                        </label>
                        <div class="flex items-center justify-end gap-3 pt-2">
                            <button
                                type="button"
                                class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                                @click="closeUnassign"
                            >
                                {{ t('devices.form.cancel') }}
                            </button>
                            <button
                                type="submit"
                                :disabled="unassignSubmitting"
                                class="inline-flex items-center justify-center gap-2 rounded-lg bg-rose-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-rose-600/20 transition hover:-translate-y-0.5 hover:bg-rose-700 disabled:cursor-wait disabled:opacity-70"
                            >
                                {{ unassignSubmitting ? t('devices.form.submitting') : t('devices.unassign.submit') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        <ConfirmDialog
            v-if="decommissionOpen && device"
            tone="danger"
            :title="t('devices.decommission_dialog.title')"
            :message="t('devices.decommission_dialog.message', { label: device.label ?? device.name ?? device.serial_number })"
            :confirm-label="t('devices.decommission')"
            :loading="decommissioning"
            :error="decommissionError"
            @confirm="confirmDecommission"
            @cancel="decommissionOpen = false"
        />

        <!-- ACTIVATION CODE MODAL (Lane A) ----------------------- -->
        <!-- Shows the plaintext code exactly ONCE. After close the
             code is gone — the admin would have to mint another. -->
        <div
            v-if="activationOpen"
            class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/50 backdrop-blur-sm p-4"
            @click.self="closeActivationModal"
        >
            <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-950">
                        {{ t('devices.activation.title') }}
                    </h2>
                    <button type="button" class="rounded-lg p-1 text-slate-500 hover:bg-slate-100" @click="closeActivationModal">
                        <X class="size-5" />
                    </button>
                </div>
                <p class="mt-2 text-sm text-slate-600">{{ t('devices.activation.subtitle') }}</p>

                <div v-if="activationBusy" class="mt-6 rounded-lg border border-slate-200 bg-slate-50 p-6 text-center text-sm font-medium text-slate-600">
                    {{ t('devices.activation.minting') }}
                </div>

                <div v-else-if="activationError" class="mt-6 rounded-lg border border-rose-200 bg-rose-50 px-3 py-3 text-sm font-semibold text-rose-700">
                    {{ activationError }}
                </div>

                <template v-else-if="activationCode">
                    <div class="mt-6 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">
                        {{ t('devices.activation.warning_one_shot') }}
                    </div>

                    <label class="mt-4 block">
                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                            {{ t('devices.activation.code_label') }}
                        </span>
                        <div class="mt-2 flex gap-2">
                            <input
                                id="device-activation-code-out"
                                :value="activationCode"
                                readonly
                                class="flex-1 rounded-lg border border-slate-200 px-3 py-2.5 text-sm font-mono tracking-wider text-slate-950 focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                            >
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-2.5 text-sm font-semibold transition"
                                :class="activationCopied ? 'border-teal-300 bg-teal-50 text-teal-700' : 'border-slate-200 text-slate-700 hover:bg-slate-50'"
                                @click="copyActivationCode"
                            >
                                <Copy class="size-4" />
                                {{ activationCopied ? t('devices.activation.copied') : t('devices.activation.copy') }}
                            </button>
                        </div>
                    </label>

                    <p class="mt-3 text-xs text-slate-500">
                        {{ t('devices.activation.expires_in', { minutes: activationExpiresIn ?? 30 }) }}
                    </p>
                </template>

                <div class="mt-6 flex items-center justify-end gap-3">
                    <button
                        type="button"
                        class="rounded-lg bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800"
                        @click="closeActivationModal"
                    >
                        {{ t('devices.activation.done') }}
                    </button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
