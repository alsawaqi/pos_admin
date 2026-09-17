<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '@/Components/BaseModal.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import { listBanks, type BankOption } from '@/lib/api/banks';
import { listCommissionProfiles, type CommissionProfile } from '@/lib/api/commissionProfiles';
import { listOrganizations, type Organization } from '@/lib/api/organizations';
import { assignDevice, getDevice, updateDevice, type DeviceDetail } from '@/lib/api/devices';
import { bankTerminalPayload, deviceDonationPayload, deviceIdentityPayload } from '@/lib/merchantDeviceEdit';
import { PlatformPermission } from '@/lib/permissions';

const props = defineProps<{ deviceUuid: string; companyId: number }>();
const emit = defineEmits<{ (e: 'updated'): void; (e: 'close'): void }>();
const { t } = useI18n();
const { can } = usePermissions();
const canEditDetails = computed(() => can(PlatformPermission.DevicesRegister));
const canEditBank = computed(() => can(PlatformPermission.DevicesAssign));
const section = ref<'bank' | 'details'>(canEditBank.value ? 'bank' : 'details');
const device = ref<DeviceDetail | null>(null);
const banks = ref<BankOption[]>([]);
const commissionProfiles = ref<CommissionProfile[]>([]);
const organizations = ref<Organization[]>([]);
const donationOptionsLoading = ref(false);
const donationLoadError = ref<string | null>(null);
const loading = ref(true);
const submitting = ref(false);
const loadError = ref<string | null>(null);
const bankLoadError = ref<string | null>(null);
const error = ref<string | null>(null);
const success = ref<string | null>(null);
const fieldErrors = ref<Record<string, string[]>>({});
const bankForm = reactive({ bank_id: 0, terminal_id: '', terminal_pin: '', use_default_pin: false });
const identityForm = reactive({ name: '', label: '', serial_number: '', kiosk_id: '' });
const donationForm = reactive({ commission_profile_id: 0, organization_id: 0 });
const fields = ['name', 'label', 'serial_number', 'kiosk_id'] as const;
const inputClass = 'mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100';
const currentBankMissing = computed(() => device.value?.bank_id && !banks.value.some(bank => bank.id === device.value?.bank_id));
const currentProfileMissing = computed(() => device.value?.commission_profile_id && !commissionProfiles.value.some(profile => profile.id === device.value?.commission_profile_id));
const currentOrganizationMissing = computed(() => device.value?.organization_id && !organizations.value.some(organization => organization.id === device.value?.organization_id));
const bankBindingChanged = computed(() => bankForm.bank_id !== device.value?.bank_id || bankForm.terminal_id.trim() !== (device.value?.terminal_id ?? ''));
const canSave = computed(() => !loading.value && !loadError.value && !submitting.value && device.value && (
    section.value === 'bank'
        ? canEditBank.value && !bankLoadError.value && !!bankForm.bank_id && !!bankForm.terminal_id.trim()
        : canEditDetails.value && !!identityForm.serial_number.trim()
            && !donationOptionsLoading.value && !donationLoadError.value
            && donationForm.commission_profile_id > 0 && donationForm.organization_id > 0
));

function resetBankForm(d: DeviceDetail): void {
    bankForm.bank_id = d.bank_id ?? 0;
    bankForm.terminal_id = d.terminal_id ?? '';
    // Existing credentials are never prefilled into the input.
    bankForm.terminal_pin = '';
    bankForm.use_default_pin = false;
}

function resetDonationForm(d: DeviceDetail): void {
    donationForm.commission_profile_id = d.commission_profile_id ?? 0;
    donationForm.organization_id = d.organization_id ?? 0;
}

async function loadDonationOptions(): Promise<void> {
    donationOptionsLoading.value = true;
    donationLoadError.value = null;
    try {
        const [profilesResponse, organizationsResponse] = await Promise.all([
            listCommissionProfiles(),
            listOrganizations(),
        ]);
        commissionProfiles.value = profilesResponse.data;
        organizations.value = organizationsResponse.data;
    } catch {
        donationLoadError.value = t('merchants.devices.edit.donations_failed');
    } finally {
        donationOptionsLoading.value = false;
    }
}

async function load(): Promise<void> {
    loading.value = true;
    loadError.value = null;
    bankLoadError.value = null;
    try {
        const response = await getDevice(props.deviceUuid);
        if (response.data.company_id !== props.companyId) throw new Error(t('merchants.devices.edit.assignment_changed'));
        device.value = response.data;
        for (const key of fields) identityForm[key] = response.data[key] ?? '';
        resetBankForm(response.data);
        resetDonationForm(response.data);
        if (canEditDetails.value) await loadDonationOptions();
        if (canEditBank.value) {
            try { banks.value = (await listBanks()).data; }
            catch { bankLoadError.value = t('merchants.devices.edit.banks_failed'); }
        }
    } catch {
        loadError.value = t('merchants.devices.edit.load_failed');
    } finally {
        loading.value = false;
    }
}

function selectSection(value: 'bank' | 'details'): void {
    section.value = value;
    error.value = null;
    success.value = null;
    fieldErrors.value = {};
}

async function submit(): Promise<void> {
    if (!canSave.value || !device.value) return;
    submitting.value = true;
    error.value = null;
    success.value = null;
    fieldErrors.value = {};
    try {
        // Refuse a stale merchant/branch binding rather than reassigning a device
        // that another administrator has moved while this dialog was open.
        const current = (await getDevice(props.deviceUuid)).data;
        if (current.company_id !== props.companyId || current.branch_id !== device.value.branch_id) {
            throw new Error(t('merchants.devices.edit.assignment_changed'));
        }
        if (section.value === 'bank') {
            if (current.bank_id !== device.value.bank_id || current.terminal_id !== device.value.terminal_id || current.terminal_pin !== device.value.terminal_pin) {
                throw new Error(t('merchants.devices.edit.settings_changed'));
            }
            let payload;
            try { payload = bankTerminalPayload(current, bankForm); }
            catch (err) { throw new Error(t(`merchants.devices.edit.${(err as Error).message}`)); }
            device.value = payload ? (await assignDevice(props.deviceUuid, payload)).data : current;
            resetBankForm(device.value);
        } else {
            const payload = {
                ...deviceIdentityPayload(device.value, identityForm),
                ...deviceDonationPayload(device.value, donationForm),
            };
            device.value = Object.keys(payload).length ? (await updateDevice(props.deviceUuid, payload)).data : current;
            for (const key of fields) identityForm[key] = device.value[key] ?? '';
            resetDonationForm(device.value);
        }
        success.value = t('merchants.devices.edit.saved');
        emit('updated');
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            fieldErrors.value = err.payload.errors;
            error.value = t('devices.form.validation_summary');
        } else {
            // Do not echo request payloads (which may contain a bank password).
            error.value = err instanceof ApiError ? t('merchants.devices.edit.save_failed')
                : err instanceof Error ? err.message : t('merchants.devices.edit.save_failed');
        }
    } finally {
        submitting.value = false;
    }
}

onMounted(() => void load());
</script>

<template>
    <BaseModal :title="t('devices.edit')" size="xl" :loading="submitting" @close="emit('close')">
        <div v-if="loading" class="py-6 text-sm text-slate-500">{{ t('common.loading') }}</div>
        <div v-else-if="loadError" role="alert" class="space-y-3 text-sm text-rose-700">
            <p>{{ loadError }}</p>
            <button type="button" class="font-semibold underline" @click="load">{{ t('merchants.devices.edit.reload') }}</button>
        </div>
        <template v-else-if="device">
            <div class="mb-5 rounded-xl bg-slate-50 px-4 py-3 text-sm">
                <p class="font-semibold text-slate-900">{{ device.label || device.name || device.serial_number }}</p>
                <p class="mt-1 break-words text-slate-500">{{ device.serial_number }} · {{ device.branch?.name ?? '—' }}</p>
            </div>
            <div class="mb-5 flex gap-2" role="group" :aria-label="t('devices.edit')">
                <button v-if="canEditBank" type="button" :disabled="submitting" :aria-pressed="section === 'bank'" class="rounded-lg px-4 py-2 text-sm font-semibold" :class="section === 'bank' ? 'bg-teal-50 text-teal-800' : 'text-slate-600 hover:bg-slate-50'" @click="selectSection('bank')">{{ t('merchants.devices.edit.bank_section') }}</button>
                <button v-if="canEditDetails" type="button" :disabled="submitting" :aria-pressed="section === 'details'" class="rounded-lg px-4 py-2 text-sm font-semibold" :class="section === 'details' ? 'bg-teal-50 text-teal-800' : 'text-slate-600 hover:bg-slate-50'" @click="selectSection('details')">{{ t('merchants.devices.edit.details_section') }}</button>
            </div>
            <p v-if="success" role="status" class="mb-4 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">{{ success }}</p>
            <p v-if="error" role="alert" class="mb-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{{ error }}</p>
            <form id="merchant-device-edit-form" @submit.prevent="submit">
                <fieldset :disabled="submitting" class="space-y-4">
                    <template v-if="section === 'bank' && canEditBank">
                        <p v-if="bankLoadError" role="alert" class="text-sm text-rose-700">{{ bankLoadError }}</p>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('merchants.devices.assign.bank') }}</span>
                            <select v-model.number="bankForm.bank_id" required :class="inputClass">
                                <option :value="0" disabled>{{ t('merchants.devices.assign.select_bank') }}</option>
                                <option v-if="currentBankMissing" :value="device.bank_id">{{ device.bank?.name ?? device.bank_id }} — {{ t('merchants.devices.edit.current_bank') }}</option>
                                <option v-for="bank in banks" :key="bank.id" :value="bank.id">{{ bank.name }} — {{ bank.softpos_label }}</option>
                            </select>
                            <p v-if="fieldErrors.bank_id" class="mt-1 text-xs text-rose-600">{{ fieldErrors.bank_id[0] }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('merchants.devices.assign.terminal_id') }}</span>
                            <input v-model="bankForm.terminal_id" type="text" maxlength="64" required dir="ltr" :class="inputClass">
                            <p v-if="fieldErrors.terminal_id" class="mt-1 text-xs text-rose-600">{{ fieldErrors.terminal_id[0] }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('merchants.devices.edit.password') }}</span>
                            <input v-model="bankForm.terminal_pin" type="password" autocomplete="new-password" maxlength="32" :disabled="bankForm.use_default_pin" dir="ltr" :class="inputClass">
                            <p class="mt-1 text-xs text-slate-500">{{ t(bankBindingChanged ? 'merchants.devices.edit.new_terminal_password' : 'merchants.devices.edit.keep_password') }}</p>
                            <p v-if="fieldErrors.terminal_pin" class="mt-1 text-xs text-rose-600">{{ fieldErrors.terminal_pin[0] }}</p>
                        </label>
                        <label class="flex items-start gap-2 text-sm text-slate-600">
                            <input v-model="bankForm.use_default_pin" type="checkbox" class="mt-1 accent-teal-600">
                            <span>{{ t('merchants.devices.edit.use_default') }}</span>
                        </label>
                    </template>
                    <div v-else-if="canEditDetails" class="grid gap-4 sm:grid-cols-2">
                        <label v-for="field in fields" :key="field" class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t(`devices.fields.${field}`) }}</span>
                            <input v-model="identityForm[field]" type="text" :required="field === 'serial_number'" :maxlength="field === 'name' ? 191 : 128" :class="inputClass">
                            <p v-if="fieldErrors[field]" class="mt-1 text-xs text-rose-600">{{ fieldErrors[field][0] }}</p>
                        </label>
                        <div class="space-y-4 border-t border-slate-200 pt-4 sm:col-span-2">
                            <h3 class="text-sm font-semibold text-slate-900">{{ t('merchants.devices.edit.donations_title') }}</h3>
                            <div v-if="donationLoadError" role="alert" class="space-y-2 text-sm text-rose-700">
                                <p>{{ donationLoadError }}</p>
                                <button type="button" :disabled="donationOptionsLoading" class="font-semibold underline" @click="loadDonationOptions">{{ t('merchants.devices.edit.reload') }}</button>
                            </div>
                            <p v-if="donationOptionsLoading" class="text-sm text-slate-500">{{ t('common.loading') }}</p>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <label class="block">
                                    <span class="text-sm font-medium text-slate-700">{{ t('devices.fields.commission_profile') }}</span>
                                    <select v-model.number="donationForm.commission_profile_id" required :disabled="donationOptionsLoading || !!donationLoadError" :class="inputClass">
                                        <option :value="0" disabled>{{ t('devices.form.select_commission_profile') }}</option>
                                        <option v-if="currentProfileMissing" :value="device.commission_profile_id">{{ device.commission_profile?.name ?? device.commission_profile_id }} — {{ t('merchants.devices.edit.current_selection') }}</option>
                                        <option v-for="profile in commissionProfiles" :key="profile.id" :value="profile.id">{{ profile.name }}</option>
                                    </select>
                                    <p class="mt-1 text-xs text-slate-500">{{ t('devices.form.commission_profile_help') }}</p>
                                    <p v-if="fieldErrors.commission_profile_id" class="mt-1 text-xs text-rose-600">{{ fieldErrors.commission_profile_id[0] }}</p>
                                </label>
                                <label class="block">
                                    <span class="text-sm font-medium text-slate-700">{{ t('devices.fields.organization') }}</span>
                                    <select v-model.number="donationForm.organization_id" required :disabled="donationOptionsLoading || !!donationLoadError" :class="inputClass">
                                        <option :value="0" disabled>{{ t('devices.form.select_organization') }}</option>
                                        <option v-if="currentOrganizationMissing" :value="device.organization_id">{{ device.organization?.name ?? device.organization_id }} — {{ t('merchants.devices.edit.current_selection') }}</option>
                                        <option v-for="organization in organizations" :key="organization.id" :value="organization.id">{{ organization.name }}</option>
                                    </select>
                                    <p class="mt-1 text-xs text-slate-500">{{ t('devices.form.organization_help') }}</p>
                                    <p v-if="fieldErrors.organization_id" class="mt-1 text-xs text-rose-600">{{ fieldErrors.organization_id[0] }}</p>
                                </label>
                            </div>
                        </div>
                    </div>
                </fieldset>
            </form>
        </template>
        <template #footer>
            <div class="flex flex-wrap justify-end gap-3">
                <button type="button" :disabled="submitting" class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 disabled:opacity-50" @click="emit('close')">{{ t('merchants.devices.edit.close') }}</button>
                <button type="submit" form="merchant-device-edit-form" :disabled="!canSave" class="rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50">{{ submitting ? t('common.saving') : t(section === 'bank' ? 'merchants.devices.edit.save_bank' : 'merchants.devices.edit.save_details') }}</button>
            </div>
        </template>
    </BaseModal>
</template>
