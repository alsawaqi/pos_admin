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
 *
 * LAUNCH-P1: the round-up commission profile + organization (P1-9) and the
 * location mode (2a, "This branch location" needs a branch with a location)
 * are chosen here too. A customer tablet may be assigned without a terminal.
 */
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '@/Components/BaseModal.vue';
import { ApiError } from '@/lib/api';
import { listBanks, type BankOption } from '@/lib/api/banks';
import { listBranches, type BranchListItem } from '@/lib/api/branches';
import { listCommissionProfiles, type CommissionProfile } from '@/lib/api/commissionProfiles';
import { listMerchants, type MerchantListItem } from '@/lib/api/merchants';
import { listOrganizations, type Organization } from '@/lib/api/organizations';
import { assignDevice, listDevices, type DeviceListItem, type DeviceLocationMode } from '@/lib/api/devices';

const props = defineProps<{
    companyId?: number;
    device?: DeviceListItem;
    /** The merchant's branches, loaded by the parent (Show.vue). */
    branches?: BranchListItem[];
}>();

const emit = defineEmits<{
    (e: 'assigned'): void;
    (e: 'close'): void;
}>();

const { t } = useI18n();

const pool = ref<DeviceListItem[]>([]);
const companies = ref<MerchantListItem[]>([]);
const branchOptions = ref<BranchListItem[]>(props.branches ?? []);
const banks = ref<BankOption[]>([]);
const commissionProfiles = ref<CommissionProfile[]>([]);
const organizations = ref<Organization[]>([]);
const loading = ref(true);
const loadError = ref<string | null>(null);

const submitting = ref(false);
const generalError = ref<string | null>(null);
const fieldErrors = ref<Record<string, string[]>>({});

const form = reactive({
    device_uuid: props.device?.uuid ?? '',
    company_id: props.companyId ?? props.device?.company_id ?? 0,
    override_reason: '',
    terminal_transfer_reason: '',
    branch_id: props.device?.branch_id ?? 0,
    bank_id: props.device?.bank_id ?? 0,
    terminal_id: props.device?.terminal_id ?? '',
    // Blank keeps the existing PIN; use_default_pin explicitly clears it.
    terminal_pin: '',
    use_default_pin: false,
    // Round-up settings belong to the assignment: prefilled only while the
    // device stays with its current merchant (cleared when it changes).
    commission_profile_id: props.device?.commission_profile_id ?? 0,
    organization_id: props.device?.organization_id ?? 0,
    location_mode: (props.device?.branch_id ? props.device?.location_mode : undefined) ?? 'branch' as DeviceLocationMode,
});

const selectedDevice = computed(() => pool.value.find(device => device.uuid === form.device_uuid) ?? props.device);
const terminalOptional = computed(() => selectedDevice.value?.device_type === 'customer_tablet');
const selectedBranch = computed(() => branchOptions.value.find(branch => branch.id === form.branch_id));
const branchHasLocation = computed(() => selectedBranch.value === undefined
    || (selectedBranch.value.latitude !== null && selectedBranch.value.longitude !== null));
const canSubmit = computed(() => !submitting.value && !loading.value && pool.value.length > 0
    && !!form.device_uuid && !!form.branch_id
    && (terminalOptional.value ? (!form.bank_id === !form.terminal_id.trim()) : (!!form.bank_id && !!form.terminal_id.trim()))
    // A customer tablet takes no payments: round-up settings are optional.
    && (terminalOptional.value || (form.commission_profile_id > 0 && form.organization_id > 0))
    && (form.location_mode === 'any' || branchHasLocation.value));

function deviceLabel(device: DeviceListItem): string {
    const name = device.label ?? device.name ?? '';
    return name ? `${device.serial_number} — ${name}` : device.serial_number;
}

async function loadOptions(): Promise<void> {
    loading.value = true;
    loadError.value = null;
    try {
        const [devicesResponse, banksResponse, profilesResponse, organizationsResponse] = await Promise.all([
            props.device ? Promise.resolve({ data: [props.device] }) : listDevices({ unassigned: true, per_page: 100 }),
            listBanks(),
            listCommissionProfiles(),
            listOrganizations(),
        ]);
        pool.value = devicesResponse.data;
        banks.value = banksResponse.data;
        commissionProfiles.value = profilesResponse.data;
        organizations.value = organizationsResponse.data;
        if (!props.companyId) {
            companies.value = (await listMerchants({ per_page: 100 })).data;
            await loadBranchOptions();
        }
    } catch (err) {
        loadError.value = err instanceof Error ? err.message : 'Failed to load options';
    } finally {
        loading.value = false;
    }
}

async function submit(): Promise<void> {
    if (!canSubmit.value) {
        return;
    }
    submitting.value = true;
    generalError.value = null;
    fieldErrors.value = {};
    try {
        await assignDevice(form.device_uuid, {
            company_id: form.company_id,
            override_reason: form.override_reason.trim() || undefined,
            terminal_transfer_reason: form.terminal_transfer_reason.trim() || undefined,
            branch_id: form.branch_id,
            bank_id: form.bank_id || null,
            terminal_id: form.terminal_id.trim() || null,
            use_default_pin: form.use_default_pin,
            terminal_pin: form.terminal_pin.trim() !== '' ? form.terminal_pin.trim() : null,
            commission_profile_id: form.commission_profile_id || undefined,
            organization_id: form.organization_id || undefined,
            location_mode: form.location_mode,
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

async function loadBranchOptions(): Promise<void> {
    branchOptions.value = [];
    if (form.company_id) {
        branchOptions.value = (await listBranches({ company_id: form.company_id, per_page: 100 })).data;
    }
}
watch(() => form.company_id, async (companyId) => {
    form.branch_id = 0;
    // P1-9: never carry the old merchant's round-up settings to a new one.
    if (companyId !== props.device?.company_id) {
        form.commission_profile_id = 0;
        form.organization_id = 0;
    }
    await loadBranchOptions();
});
watch(() => form.branch_id, (branchId) => {
    // A new home starts in "This branch location" unless the admin picks otherwise.
    if (branchId !== props.device?.branch_id) form.location_mode = 'branch';
});
onMounted(() => void loadOptions());
</script>

<template>
    <BaseModal :title="t('merchants.devices.assign.title')" size="lg" :loading="submitting" @close="emit('close')">
        <div class="space-y-5">
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
                    <label v-if="!props.companyId" class="block">
                        <span>{{ t('devices.fields.company') }}</span>
                        <select v-model.number="form.company_id" required class="mt-1 w-full rounded-lg border p-3">
                            <option :value="0" disabled>{{ t('devices.assign.select_company') }}</option>
                            <option v-for="company in companies" :key="company.id" :value="company.id">{{ company.name }}</option>
                        </select>
                    </label>
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
                            <option v-for="branch in branchOptions" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                        </select>
                        <p v-if="fieldErrors.branch_id" class="mt-1 text-xs text-rose-600">{{ fieldErrors.branch_id[0] }}</p>
                    </label>

                    <fieldset class="rounded-lg border border-slate-200 p-3">
                        <legend class="px-1 text-sm font-medium text-slate-700">Where may this device work?</legend>
                        <label class="flex items-start gap-2 text-sm text-slate-700">
                            <input v-model="form.location_mode" type="radio" value="branch" class="mt-1 accent-teal-600" :disabled="!branchHasLocation">
                            <span>This branch location (sales outside the branch area are refused)</span>
                        </label>
                        <label class="mt-2 flex items-start gap-2 text-sm text-slate-700">
                            <input v-model="form.location_mode" type="radio" value="any" class="mt-1 accent-teal-600">
                            <span>Any location</span>
                        </label>
                        <p v-if="!branchHasLocation" class="mt-2 text-xs text-amber-800">This branch has no location set. Set the branch location first, or choose "Any location".</p>
                        <p v-if="fieldErrors.location_mode" class="mt-1 text-xs text-rose-600">{{ fieldErrors.location_mode[0] }}</p>
                    </fieldset>

                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('merchants.devices.assign.bank') }}<template v-if="terminalOptional"> (optional for a customer tablet)</template></span>
                        <select v-model.number="form.bank_id" :required="!terminalOptional" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <option :value="0" :disabled="!terminalOptional">{{ terminalOptional ? 'No card terminal' : t('merchants.devices.assign.select_bank') }}</option>
                            <option v-for="bank in banks" :key="bank.id" :value="bank.id">{{ bank.name }} — {{ bank.softpos_label }}</option>
                        </select>
                        <p v-if="fieldErrors.bank_id" class="mt-1 text-xs text-rose-600">{{ fieldErrors.bank_id[0] }}</p>
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('merchants.devices.assign.terminal_id') }}</span>
                        <input v-model="form.terminal_id" type="text" :required="!terminalOptional" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p class="mt-1 text-xs text-slate-500">{{ t('merchants.devices.assign.terminal_help') }}</p>
                        <p v-if="fieldErrors.terminal_id" class="mt-1 text-xs text-rose-600">{{ fieldErrors.terminal_id[0] }}</p>
                    </label>

                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('merchants.devices.assign.terminal_pin') }}</span>
                        <input v-model="form.terminal_pin" :disabled="form.use_default_pin" type="password" autocomplete="new-password" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p class="mt-1 text-xs text-slate-500">{{ t('merchants.devices.edit.keep_password') }}</p>
                        <p v-if="fieldErrors.terminal_pin" class="mt-1 text-xs text-rose-600">{{ fieldErrors.terminal_pin[0] }}</p>
                    </label>
                    <label class="flex items-center gap-2">
                        <input v-model="form.use_default_pin" type="checkbox">
                        {{ t('merchants.devices.edit.use_default') }}
                    </label>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('devices.fields.commission_profile') }}</span>
                            <select v-model.number="form.commission_profile_id" :required="!terminalOptional" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <option :value="0" :disabled="!terminalOptional">{{ terminalOptional ? 'None (a customer tablet takes no payments)' : t('devices.form.select_commission_profile') }}</option>
                                <option v-for="profile in commissionProfiles" :key="profile.id" :value="profile.id">{{ profile.name }}</option>
                            </select>
                            <p v-if="fieldErrors.commission_profile_id" class="mt-1 text-xs text-rose-600">{{ fieldErrors.commission_profile_id[0] }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('devices.fields.organization') }}</span>
                            <select v-model.number="form.organization_id" :required="!terminalOptional" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <option :value="0" :disabled="!terminalOptional">{{ terminalOptional ? 'None (a customer tablet takes no payments)' : t('devices.form.select_organization') }}</option>
                                <option v-for="organization in organizations" :key="organization.id" :value="organization.id">{{ organization.name }}</option>
                            </select>
                            <p v-if="fieldErrors.organization_id" class="mt-1 text-xs text-rose-600">{{ fieldErrors.organization_id[0] }}</p>
                        </label>
                    </div>

                    <label class="block">
                        <span>Super Admin reason for transferring this terminal between merchants</span>
                        <textarea v-model="form.terminal_transfer_reason" maxlength="1000" class="mt-1 w-full rounded-lg border p-3" />
                        <p v-if="fieldErrors.terminal_transfer_reason" class="text-sm text-rose-600">{{ fieldErrors.terminal_transfer_reason[0] }}</p>
                    </label>
                    <label class="block">
                        <span>Super Admin move override reason (optional)</span>
                        <textarea v-model="form.override_reason" maxlength="1000" class="mt-1 w-full rounded-lg border p-3" />
                        <p class="text-sm text-amber-800">An override quarantines unsent data; it will not be delivered.</p>
                        <p v-if="fieldErrors.device" class="text-sm text-rose-600">{{ fieldErrors.device[0] }}</p>
                        <p v-if="fieldErrors.override_reason" class="text-sm text-rose-600">{{ fieldErrors.override_reason[0] }}</p>
                    </label>
                </template>
            </template>
        </div>

        <template #footer>
            <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="emit('close')">
                {{ t('common.cancel') }}
            </button>
            <button
                type="button"
                :disabled="!canSubmit"
                class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                @click="submit"
            >
                {{ submitting ? t('merchants.devices.assign.submitting') : t('merchants.devices.assign.submit') }}
            </button>
        </template>
    </BaseModal>
</template>
