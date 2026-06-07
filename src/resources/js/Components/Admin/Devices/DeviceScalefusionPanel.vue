<script setup lang="ts">
/**
 * Live scalefusion (MDM) panel for a device — the "Live" tab on the
 * device detail page. Renders the rich telemetry (RAM / storage / CPU /
 * thermals / battery / signal), the technical / network / management
 * detail, and the remote-control actions (reboot, alarm, lock/unlock,
 * broadcast message, and the generic action incl. the destructive
 * factory reset / wipe / delete). Charts are plain inline SVG — no
 * charting dependency. The GPS map lives in a sibling component.
 *
 * Joined to scalefusion purely by the device's kiosk_id; when that's
 * blank the device isn't enrolled and we say so instead of calling out.
 */

import {
    AlertTriangle, BellRing, Lock, LockOpen, MessageSquare, MonitorSmartphone,
    Power, RefreshCw, Settings2,
} from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '@/Components/BaseModal.vue';
import ConfirmDialog from '@/Components/Admin/ConfirmDialog.vue';
import DeviceLocationMap from './DeviceLocationMap.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    alarmDevice, broadcastDeviceMessage, clearDeviceAppData, getDeviceScalefusion,
    getDeviceScalefusionLocations, lockDevice, rebootDevice, runDeviceAction, unlockDevice,
    type DeviceDetail, type ScalefusionActionType, type ScalefusionDeviceDetail,
    type ScalefusionLocationPoint,
} from '@/lib/api/devices';
import { PlatformPermission } from '@/lib/permissions';

const props = defineProps<{ device: DeviceDetail }>();

const { t } = useI18n();
const { can } = usePermissions();

const canControl = computed(() => can(PlatformPermission.DevicesControl));
const kioskId = computed(() => (props.device.kiosk_id ?? '').trim());
const isEnrolled = computed(() => kioskId.value !== '');

// --- Detail fetch --------------------------------------------------
const raw = ref<ScalefusionDeviceDetail | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);

// Scalefusion's single-device endpoint sometimes wraps the payload in
// a `device` envelope and sometimes returns it flat — unwrap either.
const d = computed<ScalefusionDeviceDetail | null>(() => raw.value?.device ?? raw.value ?? null);

async function load(): Promise<void> {
    if (!isEnrolled.value) return;
    loading.value = true;
    error.value = null;
    try {
        const response = await getDeviceScalefusion(props.device.uuid);
        raw.value = response.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : t('devices.scalefusion.unreachable');
    } finally {
        loading.value = false;
    }
}

onMounted(() => void load());

// --- Numeric helpers + telemetry -----------------------------------
function num(value: unknown): number | null {
    const n = typeof value === 'string' ? Number(value) : (value as number);
    return typeof n === 'number' && Number.isFinite(n) ? n : null;
}

// Assumed MB in -> GB out once large enough. The percentage (the hero
// number the user wants) is unit-agnostic, so it's always correct.
function formatCapacity(value: number | null): string {
    if (value === null) return '—';
    return value >= 1024 ? `${(value / 1024).toFixed(1)} GB` : `${Math.round(value)} MB`;
}

const CIRC = 2 * Math.PI * 40;
function dash(percent: number | null): string {
    const p = Math.max(0, Math.min(100, percent ?? 0));
    return `${(p / 100) * CIRC} ${CIRC}`;
}

const ramTotal = computed(() => num(d.value?.total_ram_size));
const ramUsed = computed(() => num(d.value?.ram_usage));
const ramFree = computed(() => (ramTotal.value !== null && ramUsed.value !== null ? ramTotal.value - ramUsed.value : null));
const ramPercent = computed(() => (ramTotal.value && ramUsed.value !== null ? Math.round((ramUsed.value / ramTotal.value) * 100) : null));

const storageTotal = computed(() => num(d.value?.storage_info?.total_internal_storage));
const storageAvail = computed(() => num(d.value?.storage_info?.total_internal_storage_avbl));
const storageUsed = computed(() => (storageTotal.value !== null && storageAvail.value !== null ? storageTotal.value - storageAvail.value : null));
const storagePercent = computed(() => (storageTotal.value && storageUsed.value !== null ? Math.round((storageUsed.value / storageTotal.value) * 100) : null));

const cpuUsage = computed(() => num(d.value?.cpu_usage));
const signal = computed(() => num(d.value?.sim_signal_strength));
const batteryHealth = computed(() => d.value?.battery_health ?? '—');
const batteryLevel = computed(() => num(d.value?.battery_status));

const temps = computed(() => [
    { key: 'cpu', label: t('devices.scalefusion.cpu'), value: num(d.value?.cpu_temp_in_celsius), color: '#ef4444' },
    { key: 'battery', label: t('devices.scalefusion.battery'), value: num(d.value?.battery_temp_in_celsius), color: '#f59e0b' },
    { key: 'screen', label: t('devices.scalefusion.screen'), value: num(d.value?.screen_temp_in_celsius), color: '#3b82f6' },
]);
const hasTemps = computed(() => temps.value.some((row) => row.value !== null));

const wifiList = computed(() => (Array.isArray(d.value?.avbl_wifi_ssids) ? d.value!.avbl_wifi_ssids!.slice(0, 6) : []));
const wifiCount = computed(() => (Array.isArray(d.value?.avbl_wifi_ssids) ? d.value!.avbl_wifi_ssids!.length : 0));

const connectionStatus = computed(() => d.value?.connection_status ?? null);
const isOnline = computed(() => (connectionStatus.value ?? '').toLowerCase() === 'online');
const isLocked = computed(() => d.value?.locked === true);
const isCharging = computed(() => d.value?.battery_charging === true);

const technical = computed(() => [
    { label: t('devices.scalefusion.model_os'), value: [d.value?.model, d.value?.app_version_name, d.value?.os_version ? `Android ${d.value?.os_version}` : null].filter(Boolean).join(' · ') || '—' },
    { label: t('devices.scalefusion.wifi_ssid'), value: d.value?.connected_wifi_ssid ?? '—' },
    { label: t('devices.scalefusion.ip_address'), value: d.value?.ip_address ?? '—' },
    { label: t('devices.scalefusion.serial'), value: d.value?.build_serial_no ?? d.value?.serial_no ?? '—' },
    { label: t('devices.scalefusion.app_version'), value: d.value?.app_version_name ?? '—' },
    { label: t('devices.scalefusion.imei'), value: d.value?.imei_no ?? '—' },
]);

const network = computed(() => [
    { label: t('devices.scalefusion.public_ip'), value: d.value?.public_ip ?? '—' },
    { label: t('devices.scalefusion.phone'), value: d.value?.phone_no ?? '—' },
    { label: t('devices.scalefusion.sim_network'), value: d.value?.sim_network ?? '—' },
    { label: t('devices.scalefusion.network_type'), value: d.value?.sim1_network_type ?? '—' },
    { label: t('devices.scalefusion.device_group'), value: d.value?.device_group?.name ?? '—' },
    { label: t('devices.scalefusion.device_profile'), value: d.value?.device_profile?.name ?? '—' },
]);

const management = computed(() => [
    { label: t('devices.scalefusion.enrollment_mode'), value: d.value?.management_details?.enrollment_mode ?? '—' },
    { label: t('devices.scalefusion.enrollment_status'), value: d.value?.management_details?.enrollment_status ?? d.value?.enrollment_status ?? '—' },
    { label: t('devices.scalefusion.management_state'), value: d.value?.management_details?.management_state ?? d.value?.management_state ?? '—' },
    { label: t('devices.scalefusion.license_expiry'), value: d.value?.license?.expire_date ?? '—' },
    { label: t('devices.scalefusion.battery_health'), value: batteryHealth.value },
    { label: t('devices.scalefusion.attestation'), value: d.value?.device_attestation_status ?? '—' },
]);

// --- Location / GPS route ------------------------------------------
const locationDate = ref(new Date().toISOString().slice(0, 10));
const routePoints = ref<ScalefusionLocationPoint[]>([]);
const routeLoading = ref(false);
const routeError = ref<string | null>(null);
const routeLoaded = ref(false);

const currentLat = computed(() => num(d.value?.location?.lat));
const currentLng = computed(() => num(d.value?.location?.lng));
const latestAddress = computed(() => d.value?.location?.address ?? null);
const routeCount = computed(() => routePoints.value.length);
const routeAccuracy = computed(() => {
    const vals = routePoints.value.map((p) => p.accuracy).filter((a): a is number => typeof a === 'number');
    return vals.length ? Math.round((vals.reduce((sum, a) => sum + a, 0) / vals.length) * 10) / 10 : null;
});
const routeDistanceKm = computed(() => {
    const pts = routePoints.value
        .filter((p) => typeof p.latitude === 'number' && typeof p.longitude === 'number')
        .map((p) => ({ lat: p.latitude as number, lng: p.longitude as number }));
    let km = 0;
    for (let i = 1; i < pts.length; i++) {
        const a = pts[i - 1];
        const b = pts[i];
        if (a && b) km += haversine(a.lat, a.lng, b.lat, b.lng);
    }
    return Math.round(km * 100) / 100;
});

function haversine(lat1: number, lon1: number, lat2: number, lon2: number): number {
    const R = 6371;
    const dLat = ((lat2 - lat1) * Math.PI) / 180;
    const dLon = ((lon2 - lon1) * Math.PI) / 180;
    const a = Math.sin(dLat / 2) ** 2 + Math.cos((lat1 * Math.PI) / 180) * Math.cos((lat2 * Math.PI) / 180) * Math.sin(dLon / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(a));
}

function pointTime(p: ScalefusionLocationPoint): string {
    if (p.created_at_tz) return p.created_at_tz;
    if (typeof p.date_time === 'number') return new Date(p.date_time * 1000).toLocaleString();
    return '—';
}

async function trackRoute(): Promise<void> {
    routeLoading.value = true;
    routeError.value = null;
    try {
        const response = await getDeviceScalefusionLocations(props.device.uuid, locationDate.value);
        routePoints.value = response.data;
        routeLoaded.value = true;
    } catch (err) {
        routeError.value = errorMessage(err, t('devices.scalefusion.unreachable'));
    } finally {
        routeLoading.value = false;
    }
}

// --- Toast ---------------------------------------------------------
const toast = reactive({ visible: false, tone: 'success' as 'success' | 'error', title: '' });
let toastTimer: ReturnType<typeof setTimeout> | undefined;
function showToast(ok: boolean, actionLabel: string): void {
    toast.visible = true;
    toast.tone = ok ? 'success' : 'error';
    toast.title = t(ok ? 'devices.scalefusion.toast_sent' : 'devices.scalefusion.toast_failed', { action: actionLabel });
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toast.visible = false; }, 4200);
}

function errorMessage(err: unknown, fallback: string): string {
    if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
        return String((err.payload as { message?: unknown }).message ?? fallback);
    }
    return err instanceof Error ? err.message : fallback;
}

// --- Simple confirm-then-run actions -------------------------------
type Runner = () => Promise<{ ok: boolean }>;
const confirmState = reactive<{ open: boolean; label: string; tone: 'danger' | 'primary'; loading: boolean; error: string | null; run: Runner | null }>(
    { open: false, label: '', tone: 'primary', loading: false, error: null, run: null },
);

function askConfirm(label: string, tone: 'danger' | 'primary', run: Runner): void {
    confirmState.open = true;
    confirmState.label = label;
    confirmState.tone = tone;
    confirmState.error = null;
    confirmState.run = run;
}

async function confirmRun(): Promise<void> {
    if (!confirmState.run) return;
    confirmState.loading = true;
    confirmState.error = null;
    try {
        const result = await confirmState.run();
        confirmState.open = false;
        showToast(result.ok, confirmState.label);
        void load();
    } catch (err) {
        confirmState.error = errorMessage(err, t('devices.scalefusion.toast_failed', { action: confirmState.label }));
    } finally {
        confirmState.loading = false;
    }
}

const uuid = computed(() => props.device.uuid);
function doReboot(): void { askConfirm(t('devices.scalefusion.reboot'), 'primary', () => rebootDevice(uuid.value)); }
function doAlarm(): void { askConfirm(t('devices.scalefusion.alarm'), 'primary', () => alarmDevice(uuid.value)); }
function doLock(): void { askConfirm(t('devices.scalefusion.lock'), 'primary', () => lockDevice(uuid.value)); }
function doUnlock(): void { askConfirm(t('devices.scalefusion.unlock'), 'primary', () => unlockDevice(uuid.value)); }
function doClearAppData(): void { askConfirm(t('devices.scalefusion.clear_app_data'), 'danger', () => clearDeviceAppData(uuid.value)); }

// --- Broadcast message modal ---------------------------------------
const messageOpen = ref(false);
const messageBusy = ref(false);
const messageError = ref<string | null>(null);
const messageForm = reactive({ sender_name: '', message_body: '', keep_ringing: true, show_as_dialog: true });

async function sendMessage(): Promise<void> {
    if (!messageForm.sender_name.trim() || !messageForm.message_body.trim()) return;
    messageBusy.value = true;
    messageError.value = null;
    try {
        const result = await broadcastDeviceMessage(uuid.value, {
            sender_name: messageForm.sender_name.trim(),
            message_body: messageForm.message_body.trim(),
            keep_ringing: messageForm.keep_ringing,
            show_as_dialog: messageForm.show_as_dialog,
        });
        messageOpen.value = false;
        showToast(result.ok, t('devices.scalefusion.message'));
    } catch (err) {
        messageError.value = errorMessage(err, t('devices.scalefusion.toast_failed', { action: t('devices.scalefusion.message') }));
    } finally {
        messageBusy.value = false;
    }
}

// --- Generic action modal ------------------------------------------
const ACTION_TYPES: ScalefusionActionType[] = [
    'screen_lock', 'shutdown', 'reboot', 'mark_as_lost', 'mark_as_found',
    'factory_reset', 'delete_device', 'buzz_device', 'rotate_filevault_key',
];
const actionOpen = ref(false);
const actionBusy = ref(false);
const actionError = ref<string | null>(null);
const actionForm = reactive({
    action_type: 'screen_lock' as ScalefusionActionType,
    lost_mode_message: '', lost_mode_footnote: '', lost_mode_phone: '', wipe_sd_card: false,
});
const isLostMode = computed(() => actionForm.action_type === 'mark_as_lost');
const isFactoryReset = computed(() => actionForm.action_type === 'factory_reset');

async function runAction(): Promise<void> {
    actionBusy.value = true;
    actionError.value = null;
    try {
        const result = await runDeviceAction(uuid.value, {
            action_type: actionForm.action_type,
            ...(isLostMode.value ? {
                lost_mode_message: actionForm.lost_mode_message || undefined,
                lost_mode_footnote: actionForm.lost_mode_footnote || undefined,
                lost_mode_phone: actionForm.lost_mode_phone || undefined,
            } : {}),
            ...(isFactoryReset.value ? { wipe_sd_card: actionForm.wipe_sd_card } : {}),
        });
        actionOpen.value = false;
        showToast(result.ok, t(`devices.scalefusion.action_types.${actionForm.action_type}`));
        void load();
    } catch (err) {
        actionError.value = errorMessage(err, t('devices.scalefusion.toast_failed', { action: t('devices.scalefusion.actions') }));
    } finally {
        actionBusy.value = false;
    }
}

function openRemoteMirror(): void {
    window.open(`https://app.scalefusion.com/cloud/dashboard/remote_mirror/${kioskId.value}`, '_blank', 'noopener');
}
</script>

<template>
    <div class="space-y-6">
        <!-- Not enrolled / loading / error states -->
        <div v-if="!isEnrolled" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">
            {{ t('devices.scalefusion.not_enrolled') }}
        </div>

        <template v-else>
            <div v-if="loading && !d" class="rounded-xl border border-slate-200 bg-white p-10 text-center text-sm font-medium text-slate-500 shadow-sm">
                {{ t('devices.scalefusion.loading') }}
            </div>
            <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                {{ error }}
            </div>

            <template v-else-if="d">
                <!-- Header: status badges + control buttons -->
                <header class="flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex items-center gap-4">
                        <div class="grid size-12 place-items-center rounded-xl bg-slate-950 text-white">
                            <MonitorSmartphone class="size-6" />
                        </div>
                        <div>
                            <p class="text-base font-semibold text-slate-950">{{ d.name ?? props.device.label ?? props.device.name ?? props.device.serial_number }}</p>
                            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs font-semibold">
                                <span :class="isOnline ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'" class="rounded-full px-2.5 py-1">
                                    {{ isOnline ? t('devices.scalefusion.online') : t('devices.scalefusion.offline') }}
                                </span>
                                <span v-if="isLocked" class="rounded-full bg-rose-100 px-2.5 py-1 text-rose-700">{{ t('devices.scalefusion.locked') }}</span>
                                <span v-if="batteryLevel !== null" class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-700">{{ batteryLevel }}%</span>
                                <span v-if="isCharging" class="rounded-full bg-amber-100 px-2.5 py-1 text-amber-700">{{ t('devices.scalefusion.charging') }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" class="grid size-9 place-items-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50" :title="t('devices.scalefusion.refresh')" @click="load">
                            <RefreshCw class="size-4" :class="{ 'animate-spin': loading }" />
                        </button>
                        <template v-if="canControl">
                            <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-amber-200 bg-white px-3 py-2 text-sm font-semibold text-amber-700 hover:bg-amber-50" @click="doReboot">
                                <Power class="size-4" /> {{ t('devices.scalefusion.reboot') }}
                            </button>
                            <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-amber-200 bg-white px-3 py-2 text-sm font-semibold text-amber-700 hover:bg-amber-50" @click="doAlarm">
                                <BellRing class="size-4" /> {{ t('devices.scalefusion.alarm') }}
                            </button>
                            <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="messageOpen = true">
                                <MessageSquare class="size-4" /> {{ t('devices.scalefusion.message') }}
                            </button>
                            <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="actionOpen = true">
                                <Settings2 class="size-4" /> {{ t('devices.scalefusion.actions') }}
                            </button>
                            <button type="button" class="grid size-9 place-items-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50" :title="t('devices.scalefusion.lock')" @click="doLock">
                                <Lock class="size-4" />
                            </button>
                            <button type="button" class="grid size-9 place-items-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50" :title="t('devices.scalefusion.unlock')" @click="doUnlock">
                                <LockOpen class="size-4" />
                            </button>
                        </template>
                    </div>
                </header>

                <!-- Telemetry: thermal + RAM + storage + stat cards -->
                <div class="grid gap-6 lg:grid-cols-3">
                    <!-- RAM -->
                    <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ t('devices.scalefusion.ram') }}</h3>
                        <div v-if="ramPercent !== null" class="mt-4 flex items-center gap-5">
                            <svg viewBox="0 0 100 100" class="size-28 shrink-0">
                                <circle cx="50" cy="50" r="40" fill="none" stroke="#dbeafe" stroke-width="12" />
                                <circle cx="50" cy="50" r="40" fill="none" stroke="#2563eb" stroke-width="12" stroke-linecap="round" :stroke-dasharray="dash(ramPercent)" transform="rotate(-90 50 50)" />
                                <text x="50" y="52" text-anchor="middle" dominant-baseline="middle" class="fill-slate-950 text-[20px] font-bold">{{ ramPercent }}%</text>
                            </svg>
                            <dl class="space-y-1.5 text-sm">
                                <div class="flex items-center gap-2"><span class="size-2.5 rounded-full bg-blue-600" /><dt class="text-slate-500">{{ t('devices.scalefusion.used') }}</dt><dd class="font-semibold text-slate-900">{{ formatCapacity(ramUsed) }}</dd></div>
                                <div class="flex items-center gap-2"><span class="size-2.5 rounded-full bg-blue-200" /><dt class="text-slate-500">{{ t('devices.scalefusion.free') }}</dt><dd class="font-semibold text-slate-900">{{ formatCapacity(ramFree) }}</dd></div>
                                <div class="flex items-center gap-2"><span class="size-2.5 rounded-full bg-slate-400" /><dt class="text-slate-500">{{ t('devices.scalefusion.total') }}</dt><dd class="font-semibold text-slate-900">{{ formatCapacity(ramTotal) }}</dd></div>
                            </dl>
                        </div>
                        <p v-else class="mt-4 text-sm text-slate-500">{{ t('devices.scalefusion.no_ram') }}</p>
                    </section>

                    <!-- Storage -->
                    <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ t('devices.scalefusion.storage') }}</h3>
                        <div v-if="storagePercent !== null" class="mt-4 flex items-center gap-5">
                            <svg viewBox="0 0 100 100" class="size-28 shrink-0">
                                <circle cx="50" cy="50" r="40" fill="none" stroke="#99f6e4" stroke-width="12" />
                                <circle cx="50" cy="50" r="40" fill="none" stroke="#0f766e" stroke-width="12" stroke-linecap="round" :stroke-dasharray="dash(storagePercent)" transform="rotate(-90 50 50)" />
                                <text x="50" y="52" text-anchor="middle" dominant-baseline="middle" class="fill-slate-950 text-[20px] font-bold">{{ storagePercent }}%</text>
                            </svg>
                            <dl class="space-y-1.5 text-sm">
                                <div class="flex items-center gap-2"><span class="size-2.5 rounded-full bg-teal-700" /><dt class="text-slate-500">{{ t('devices.scalefusion.used') }}</dt><dd class="font-semibold text-slate-900">{{ formatCapacity(storageUsed) }}</dd></div>
                                <div class="flex items-center gap-2"><span class="size-2.5 rounded-full bg-teal-300" /><dt class="text-slate-500">{{ t('devices.scalefusion.available') }}</dt><dd class="font-semibold text-slate-900">{{ formatCapacity(storageAvail) }}</dd></div>
                                <div class="flex items-center gap-2"><span class="size-2.5 rounded-full bg-slate-400" /><dt class="text-slate-500">{{ t('devices.scalefusion.total') }}</dt><dd class="font-semibold text-slate-900">{{ formatCapacity(storageTotal) }}</dd></div>
                            </dl>
                        </div>
                        <p v-else class="mt-4 text-sm text-slate-500">{{ t('devices.scalefusion.no_storage') }}</p>
                    </section>

                    <!-- Thermal -->
                    <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ t('devices.scalefusion.thermal') }}</h3>
                        <div v-if="hasTemps" class="mt-4 space-y-3">
                            <div v-for="row in temps" :key="row.key" class="flex items-center justify-between text-sm">
                                <span class="flex items-center gap-2"><span class="size-2.5 rounded-full" :style="{ backgroundColor: row.color }" />{{ row.label }}</span>
                                <span class="font-semibold text-slate-900">{{ row.value !== null ? `${row.value}°C` : '—' }}</span>
                            </div>
                        </div>
                        <p v-else class="mt-4 text-sm text-slate-500">{{ t('devices.scalefusion.no_temp') }}</p>
                    </section>
                </div>

                <!-- Stat cards: CPU load / battery health / signal / connectivity -->
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('devices.scalefusion.cpu_load') }}</p>
                        <p class="mt-2 text-2xl font-bold text-slate-950">{{ cpuUsage !== null ? `${cpuUsage}%` : '—' }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('devices.scalefusion.battery_health') }}</p>
                        <p class="mt-2 text-2xl font-bold text-slate-950">{{ batteryHealth }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('devices.scalefusion.signal_strength') }}</p>
                        <p class="mt-2 text-2xl font-bold text-slate-950">{{ signal !== null ? `${signal}/4` : '—' }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('devices.scalefusion.connectivity') }}</p>
                        <p class="mt-2 text-2xl font-bold text-slate-950">{{ wifiCount }} Wi-Fi</p>
                    </div>
                </div>

                <!-- Detail grids: technical / network / management -->
                <div class="grid gap-6 lg:grid-cols-3">
                    <section v-for="block in [{ title: t('devices.scalefusion.technical'), rows: technical }, { title: t('devices.scalefusion.network'), rows: network }, { title: t('devices.scalefusion.management'), rows: management }]" :key="block.title" class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ block.title }}</h3>
                        <dl class="mt-4 space-y-3 text-sm">
                            <div v-for="row in block.rows" :key="row.label">
                                <dt class="font-medium text-slate-500">{{ row.label }}</dt>
                                <dd class="font-semibold text-slate-900 break-words">{{ row.value }}</dd>
                            </div>
                        </dl>
                    </section>
                </div>

                <!-- Device location: map + daily GPS route -->
                <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ t('devices.scalefusion.location') }}</h3>
                            <p v-if="latestAddress" class="mt-1 text-sm text-slate-600">{{ t('devices.scalefusion.latest_address') }}: {{ latestAddress }}</p>
                        </div>
                        <form class="flex items-end gap-2" @submit.prevent="trackRoute">
                            <label class="block">
                                <span class="text-xs font-medium text-slate-500">{{ t('devices.scalefusion.route_date') }}</span>
                                <input v-model="locationDate" type="date" class="mt-1 rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            </label>
                            <button type="submit" :disabled="routeLoading" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-70">{{ t('devices.scalefusion.track_route') }}</button>
                        </form>
                    </div>

                    <div v-if="routeError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">{{ routeError }}</div>

                    <div v-if="routeCount > 0" class="mt-4 grid grid-cols-3 gap-3">
                        <div class="rounded-lg bg-slate-50 px-3 py-2 text-center">
                            <p class="text-lg font-bold text-slate-950">{{ routeCount }}</p>
                            <p class="text-xs text-slate-500">{{ t('devices.scalefusion.pings') }}</p>
                        </div>
                        <div class="rounded-lg bg-slate-50 px-3 py-2 text-center">
                            <p class="text-lg font-bold text-slate-950">{{ routeDistanceKm }} km</p>
                            <p class="text-xs text-slate-500">{{ t('devices.scalefusion.distance') }}</p>
                        </div>
                        <div class="rounded-lg bg-slate-50 px-3 py-2 text-center">
                            <p class="text-lg font-bold text-slate-950">{{ routeAccuracy !== null ? `${routeAccuracy} m` : '—' }}</p>
                            <p class="text-xs text-slate-500">{{ t('devices.scalefusion.accuracy') }}</p>
                        </div>
                    </div>

                    <div class="mt-4">
                        <DeviceLocationMap :points="routePoints" :lat="currentLat" :lng="currentLng" />
                    </div>

                    <p v-if="routeLoaded && routeCount === 0" class="mt-3 text-sm text-slate-500">{{ t('devices.scalefusion.no_route') }}</p>

                    <div v-if="routeCount > 0" class="mt-4">
                        <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('devices.scalefusion.timeline') }}</h4>
                        <ul class="mt-3 max-h-72 space-y-2 overflow-y-auto pe-1">
                            <li v-for="(p, i) in routePoints" :key="p.location_id ?? i" class="flex items-start gap-3 rounded-lg border border-slate-100 px-3 py-2 text-sm">
                                <span class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full bg-blue-600 text-xs font-bold text-white">{{ i + 1 }}</span>
                                <div class="min-w-0">
                                    <p class="font-medium text-slate-700">{{ pointTime(p) }}</p>
                                    <p v-if="p.address" class="truncate text-slate-500">{{ p.address }}</p>
                                    <p class="font-mono text-xs text-blue-600">{{ p.latitude?.toFixed(5) }}, {{ p.longitude?.toFixed(5) }}</p>
                                </div>
                                <span v-if="p.accuracy !== null" class="ms-auto shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">{{ p.accuracy }} m</span>
                            </li>
                        </ul>
                    </div>
                </section>

                <!-- Nearby Wi-Fi + remote mirror -->
                <section v-if="wifiList.length" class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ t('devices.scalefusion.nearby_wifi') }} ({{ wifiCount }})</h3>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <span v-for="ssid in wifiList" :key="ssid" class="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-medium text-slate-700">{{ ssid }}</span>
                    </div>
                </section>

                <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50" @click="openRemoteMirror">
                    <MonitorSmartphone class="size-4" /> {{ t('devices.scalefusion.remote_mirror') }}
                </button>
            </template>
        </template>

        <!-- Confirm dialog for the simple control actions -->
        <ConfirmDialog
            v-if="confirmState.open"
            :tone="confirmState.tone"
            :title="t('devices.scalefusion.confirm_title', { action: confirmState.label })"
            :message="t('devices.scalefusion.confirm_message', { action: confirmState.label })"
            :confirm-label="confirmState.label"
            :loading="confirmState.loading"
            :error="confirmState.error"
            @confirm="confirmRun"
            @cancel="confirmState.open = false"
        />

        <!-- Broadcast message modal -->
        <BaseModal v-if="messageOpen" :title="t('devices.scalefusion.message_title')" size="lg" :loading="messageBusy" @close="messageOpen = false">
            <div v-if="messageError" class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">{{ messageError }}</div>
            <form id="scalefusion-message-form" class="space-y-4" @submit.prevent="sendMessage">
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('devices.scalefusion.sender') }}</span>
                    <input v-model="messageForm.sender_name" type="text" maxlength="100" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('devices.scalefusion.body') }}</span>
                    <textarea v-model="messageForm.message_body" rows="4" maxlength="1000" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" />
                </label>
                <div class="flex gap-4 text-sm">
                    <label class="flex items-center gap-2"><input v-model="messageForm.keep_ringing" type="checkbox"> {{ t('devices.scalefusion.keep_ringing') }}</label>
                    <label class="flex items-center gap-2"><input v-model="messageForm.show_as_dialog" type="checkbox"> {{ t('devices.scalefusion.show_as_dialog') }}</label>
                </div>
            </form>
            <template #footer>
                <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="messageOpen = false">{{ t('devices.scalefusion.cancel') }}</button>
                <button type="submit" form="scalefusion-message-form" :disabled="messageBusy" class="rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white hover:bg-slate-800 disabled:opacity-70">{{ t('devices.scalefusion.send') }}</button>
            </template>
        </BaseModal>

        <!-- Generic action modal -->
        <BaseModal v-if="actionOpen" :title="t('devices.scalefusion.action_title')" size="lg" :loading="actionBusy" @close="actionOpen = false">
            <div v-if="actionError" class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">{{ actionError }}</div>
            <form id="scalefusion-action-form" class="space-y-4" @submit.prevent="runAction">
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('devices.scalefusion.action_type') }}</span>
                    <select v-model="actionForm.action_type" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <option v-for="a in ACTION_TYPES" :key="a" :value="a">{{ t(`devices.scalefusion.action_types.${a}`) }}</option>
                    </select>
                </label>
                <div v-if="actionForm.action_type === 'factory_reset' || actionForm.action_type === 'delete_device'" class="flex items-start gap-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700">
                    <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                    <span>{{ t('devices.scalefusion.confirm_message', { action: t(`devices.scalefusion.action_types.${actionForm.action_type}`) }) }}</span>
                </div>
                <template v-if="isLostMode">
                    <label class="block"><span class="text-sm font-medium text-slate-700">{{ t('devices.scalefusion.lost_message') }}</span>
                        <input v-model="actionForm.lost_mode_message" type="text" maxlength="500" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"></label>
                    <label class="block"><span class="text-sm font-medium text-slate-700">{{ t('devices.scalefusion.lost_footnote') }}</span>
                        <input v-model="actionForm.lost_mode_footnote" type="text" maxlength="500" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"></label>
                    <label class="block"><span class="text-sm font-medium text-slate-700">{{ t('devices.scalefusion.lost_phone') }}</span>
                        <input v-model="actionForm.lost_mode_phone" type="text" maxlength="100" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"></label>
                </template>
                <label v-if="isFactoryReset" class="flex items-center gap-2 text-sm"><input v-model="actionForm.wipe_sd_card" type="checkbox"> {{ t('devices.scalefusion.wipe_sd') }}</label>
            </form>
            <template #footer>
                <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="actionOpen = false">{{ t('devices.scalefusion.cancel') }}</button>
                <button type="submit" form="scalefusion-action-form" :disabled="actionBusy" class="rounded-lg bg-rose-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-rose-700 disabled:opacity-70">{{ t('devices.scalefusion.run') }}</button>
            </template>
        </BaseModal>

        <!-- Toast -->
        <div v-if="toast.visible" class="fixed end-6 top-6 z-[60] flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-semibold text-white shadow-lg" :class="toast.tone === 'success' ? 'bg-emerald-600' : 'bg-rose-600'">
            {{ toast.title }}
        </div>
    </div>
</template>
