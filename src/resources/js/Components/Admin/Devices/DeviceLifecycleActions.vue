<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ConfirmDialog from '@/Components/Admin/ConfirmDialog.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import { changeDeviceAvailability, type DeviceAvailabilityOperation, type DeviceListItem } from '@/lib/api/devices';
import { PlatformPermission } from '@/lib/permissions';

const props = defineProps<{ device: DeviceListItem }>();
const emit = defineEmits<{ (e: 'updated'): void }>();
const { t } = useI18n();
const { can } = usePermissions();
const action = ref<DeviceAvailabilityOperation | null>(null);
const busy = ref(false);
const error = ref<string | null>(null);
const disabled = computed(() => ['inactive', 'blocked'].includes(props.device.status ?? ''));
const mainAction = computed<DeviceAvailabilityOperation>(() => props.device.deleted_at ? 'restore' : disabled.value ? 'enable' : 'disable');

function open(operation: DeviceAvailabilityOperation): void {
    action.value = operation;
    error.value = null;
}

async function confirm(): Promise<void> {
    if (!action.value || busy.value) return;
    busy.value = true;
    error.value = null;
    try {
        await changeDeviceAvailability(props.device.uuid, action.value);
        action.value = null;
        emit('updated');
    } catch (err) {
        error.value = err instanceof ApiError && err.isValidationError()
            ? Object.values(err.payload.errors).flat()[0] ?? t('devices.availability.failed')
            : t('devices.availability.failed');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div v-if="can(PlatformPermission.DevicesDecommission)" class="inline-flex flex-wrap items-center justify-end gap-2">
        <button type="button" :disabled="busy" class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition disabled:opacity-50" :class="mainAction === 'disable' ? 'border-amber-200 text-amber-800 hover:bg-amber-50' : 'border-teal-200 text-teal-800 hover:bg-teal-50'" @click="open(mainAction)">
            {{ t(`devices.availability.${mainAction}`) }}
        </button>
        <button v-if="disabled && !device.deleted_at && device.terminal_id && can(PlatformPermission.DevicesAssign)" type="button" :disabled="busy" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50" @click="open('release-terminal')">
            {{ t('devices.availability.release-terminal') }}
        </button>
        <ConfirmDialog
            v-if="action"
            :title="t(`devices.availability.${action}`)"
            :message="t(`devices.availability.${action}_message`, { label: device.label || device.name || device.serial_number, terminal: device.terminal_id })"
            :confirm-label="t(`devices.availability.${action}`)"
            :tone="action === 'disable' || action === 'release-terminal' ? 'danger' : 'primary'"
            :loading="busy"
            :error="error"
            @confirm="confirm"
            @cancel="action = null"
        />
    </div>
</template>
