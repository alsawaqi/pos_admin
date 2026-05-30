<script setup lang="ts">
/**
 * Assign device — modal used INSIDE the merchant detail page's Devices tab.
 *
 * Lists the UNASSIGNED device pool (devices registered but not yet bound to any
 * merchant) and binds a chosen one to THIS merchant: pick a device + a branch +
 * the acquiring bank + the bank-issued terminal id, then assign. terminal_id +
 * bank are captured here at assign time (the terminal is issued against the
 * merchant's bank account), not at registration. Once assigned, the device
 * leaves the pool and only appears under this merchant.
 */
import { X } from 'lucide-vue-next';
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/lib/api';
import { listBanks, type BankOption } from '@/lib/api/banks';
import type { BranchListItem } from '@/lib/api/branches';
import { assignDevice, listDevices, type DeviceListItem } from '@/lib/api/devices';

const props = defineProps<{
    companyId: number;
    /** The merchant's branches, loaded by the parent (Show.vue). */
    branches: BranchListItem[];
}>();

const emit = defineEmits<{
    (e: 'assigned'): void;
    (e: 'close'): void;
}>();

const { t } = useI18n();

const pool = ref<DeviceListItem[]>([]);
const banks = ref<BankOption[]>([]);
const loading = ref(true);
const loadError = ref<string | null>(null);

const submitting = ref(false);
const generalError = ref<string | null>(null);
const fieldErrors = ref<Record<string, string[]>>({});

const form = reactive({
    device_uuid: '',
    branch_id: 0,
    bank_id: 0,
    terminal_id: '',
});

function deviceLabel(device: DeviceListItem): string {
    const name = device.label ?? device.name ?? '';
    return name ? `${device.serial_number} — ${name}` : device.serial_number;
}

async function loadOptions(): Promise<void> {
    loading.value = true;
    loadError.value = null;
    try {
        const [devicesResponse, banksResponse] = await Promise.all([
            listDevices({ unassigned: true, per_page: 100 }),
            listBanks(),
        ]);
        pool.value = devicesResponse.data;
        banks.value = banksResponse.data;
    } catch (err) {
        loadError.value = err instanceof Error ? err.message : 'Failed to load options';
    } finally {
        loading.value = false;
    }
}

async function submit(): Promise<void> {
    if (!form.device_uuid || !form.branch_id || !form.bank_id || !form.terminal_id) {
        return;
    }
    submitting.value = true;
    generalError.value = null;
    fieldErrors.value = {};
    try {
        await assignDevice(form.device_uuid, {
            company_id: props.companyId,
            branch_id: form.branch_id,
            bank_id: form.bank_id,
            terminal_id: form.terminal_id,
        });
        emit('assigned');
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            fieldErrors.value = err.payload.errors;
            generalError.value = t('merchants.devices.assign.validation_summary');
        } else if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            generalError.value = String((err.payload as { message?: unknown }).message);
        } else {
            generalError.value = err instanceof Error ? err.message : 'Failed to assign device';
        }
    } finally {
        submitting.value = false;
    }
}

onMounted(() => void loadOptions());
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/50 p-4 backdrop-blur-sm" @click.self="emit('close')">
        <div class="my-8 w-full max-w-lg rounded-2xl bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
                <h2 class="text-lg font-semibold text-slate-950">{{ t('merchants.devices.assign.title') }}</h2>
                <button type="button" class="grid size-9 place-items-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-700" @click="emit('close')">
                    <X class="size-5" />
                </button>
            </div>

            <div class="space-y-5 px-6 py-5">
                <div v-if="generalError" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                    {{ generalError }}
                </div>
                <div v-if="loadError" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                    {{ loadError }}
                </div>

                <div v-if="loading" class="py-6 text-center text-sm text-slate-500">{{ t('common.loading') }}</div>

                <template v-else>
                    <div v-if="pool.length === 0" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900">
                        {{ t('merchants.devices.assign.no_unassigned') }}
                    </div>

                    <template v-else>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('merchants.devices.assign.device') }}</span>
                            <select v-model="form.device_uuid" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <option value="" disabled>{{ t('merchants.devices.assign.select_device') }}</option>
                                <option v-for="device in pool" :key="device.uuid" :value="device.uuid">{{ deviceLabel(device) }}</option>
                            </select>
                            <p v-if="fieldErrors.device_uuid" class="mt-1 text-xs text-rose-600">{{ fieldErrors.device_uuid[0] }}</p>
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('merchants.devices.assign.branch') }}</span>
                            <select v-model.number="form.branch_id" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <option :value="0" disabled>{{ t('merchants.devices.assign.select_branch') }}</option>
                                <option v-for="branch in props.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                            </select>
                            <p v-if="fieldErrors.branch_id" class="mt-1 text-xs text-rose-600">{{ fieldErrors.branch_id[0] }}</p>
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('merchants.devices.assign.bank') }}</span>
                            <select v-model.number="form.bank_id" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <option :value="0" disabled>{{ t('merchants.devices.assign.select_bank') }}</option>
                                <option v-for="bank in banks" :key="bank.id" :value="bank.id">{{ bank.name }}</option>
                            </select>
                            <p v-if="fieldErrors.bank_id" class="mt-1 text-xs text-rose-600">{{ fieldErrors.bank_id[0] }}</p>
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('merchants.devices.assign.terminal_id') }}</span>
                            <input v-model="form.terminal_id" type="text" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p class="mt-1 text-xs text-slate-500">{{ t('merchants.devices.assign.terminal_help') }}</p>
                            <p v-if="fieldErrors.terminal_id" class="mt-1 text-xs text-rose-600">{{ fieldErrors.terminal_id[0] }}</p>
                        </label>
                    </template>
                </template>
            </div>

            <div class="flex items-center justify-end gap-3 border-t border-slate-200 px-6 py-4">
                <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="emit('close')">
                    {{ t('common.cancel') }}
                </button>
                <button
                    type="button"
                    :disabled="submitting || loading || pool.length === 0 || !form.device_uuid || !form.branch_id || !form.bank_id || !form.terminal_id"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                    @click="submit"
                >
                    {{ submitting ? t('merchants.devices.assign.submitting') : t('merchants.devices.assign.submit') }}
                </button>
            </div>
        </div>
    </div>
</template>
