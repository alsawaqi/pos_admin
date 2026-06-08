<script setup lang="ts">
/**
 * Edit Device page.
 *
 * Loads an existing device, prefills the same form as Register, and PATCHes the
 * changed fields. Edits the device's identity + catalogue + commission /
 * organization bindings. Assignment (company/branch), the bank terminal, and
 * status are NOT edited here — those have their own flows on the device detail
 * page (Assign / Unassign / Decommission).
 *
 * The Make → Model cascade is prefilled carefully: setting make_id would
 * normally fire the watcher that clears model_id, so a `prefilling` guard keeps
 * the watcher quiet until the device's current make + model are both in place.
 */

import { ArrowLeft } from 'lucide-vue-next';
import { nextTick, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import {
    getDevice,
    updateDevice,
    type DeviceType,
    type UpdateDevicePayload,
} from '@/lib/api/devices';
import { listMakes, listModels, type DeviceMake, type DeviceModel } from '@/lib/api/deviceCatalog';
import { listCommissionProfiles, type CommissionProfile } from '@/lib/api/commissionProfiles';
import { listOrganizations, type Organization } from '@/lib/api/organizations';

const { t } = useI18n();
const router = useRouter();
const route = useRoute();
const uuid = String(route.params.uuid);

const loading = ref(true);
const submitting = ref(false);
const generalError = ref<string | null>(null);
const fieldErrors = ref<Record<string, string[]>>({});

const makes = ref<DeviceMake[]>([]);
const modelsForSelectedMake = ref<DeviceModel[]>([]);
const modelsLoading = ref(false);
const commissionProfiles = ref<CommissionProfile[]>([]);
const organizations = ref<Organization[]>([]);

// Plain (non-reactive) guard: true while we prefill, so the make→model watcher
// doesn't wipe the device's current model on the initial load.
let prefilling = true;

const form = reactive<{
    serial_number: string;
    kiosk_id: string;
    commission_profile_id: number;
    organization_id: number;
    device_type: DeviceType;
    make_id: number;
    model_id: number;
    name: string;
    label: string;
}>({
    serial_number: '',
    kiosk_id: '',
    commission_profile_id: 0,
    organization_id: 0,
    device_type: 'fixed_pos',
    make_id: 0,
    model_id: 0,
    name: '',
    label: '',
});

const typeOptions: DeviceType[] = ['fixed_pos', 'handheld', 'customer_tablet'];

onMounted(async () => {
    try {
        const [makesResponse, profilesResponse, orgsResponse, deviceResponse] = await Promise.all([
            listMakes(),
            listCommissionProfiles(),
            listOrganizations(),
            getDevice(uuid),
        ]);
        makes.value = makesResponse.data;
        commissionProfiles.value = profilesResponse.data;
        organizations.value = orgsResponse.data;

        const d = deviceResponse.data;
        form.serial_number = d.serial_number ?? '';
        form.kiosk_id = d.kiosk_id ?? '';
        form.commission_profile_id = d.commission_profile_id ?? 0;
        form.organization_id = d.organization_id ?? 0;
        form.device_type = d.device_type ?? 'fixed_pos';
        form.name = d.name ?? '';
        form.label = d.label ?? '';
        form.make_id = d.make_id ?? 0;

        // Load the current make's models so the Model dropdown shows the
        // device's current selection, THEN set model_id.
        if (form.make_id) {
            const modelsResponse = await listModels(form.make_id);
            modelsForSelectedMake.value = modelsResponse.data;
        }
        form.model_id = d.model_id ?? 0;

        // Let the queued make-watcher flush (guarded) before we re-enable it.
        await nextTick();
        prefilling = false;
    } catch (err) {
        prefilling = false;
        generalError.value = err instanceof Error
            ? `Failed to load device: ${err.message}`
            : 'Failed to load device';
    } finally {
        loading.value = false;
    }
});

/** Reload the Model dropdown when the Make changes (user-driven only). */
watch(() => form.make_id, async (makeId) => {
    if (prefilling) {
        return;
    }
    form.model_id = 0;
    modelsForSelectedMake.value = [];
    if (! makeId) {
        return;
    }
    modelsLoading.value = true;
    try {
        const response = await listModels(makeId);
        modelsForSelectedMake.value = response.data;
    } catch {
        modelsForSelectedMake.value = [];
    } finally {
        modelsLoading.value = false;
    }
});

async function submit(): Promise<void> {
    submitting.value = true;
    generalError.value = null;
    fieldErrors.value = {};

    try {
        const payload: UpdateDevicePayload = {
            serial_number: form.serial_number,
            kiosk_id: form.kiosk_id,
            commission_profile_id: form.commission_profile_id,
            organization_id: form.organization_id,
            device_type: form.device_type,
            make_id: form.make_id,
            model_id: form.model_id,
            name: form.name || null,
            label: form.label || null,
        };
        const response = await updateDevice(uuid, payload);
        await router.push(`/admin/devices/${response.data.uuid}`);
    } catch (err: unknown) {
        if (err && typeof err === 'object' && 'status' in err && (err as { status: number }).status === 422) {
            const errPayload = (err as { payload?: { errors?: Record<string, string[]> } }).payload;
            fieldErrors.value = errPayload?.errors ?? {};
            generalError.value = t('devices.form.validation_summary');
        } else {
            generalError.value = err instanceof Error ? err.message : 'Failed to update device';
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <AdminLayout>
        <section class="mx-auto max-w-3xl space-y-6">
            <RouterLink :to="`/admin/devices/${uuid}`" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-slate-950">
                <ArrowLeft class="size-4" />
                {{ t('devices.back_to_detail') }}
            </RouterLink>

            <header>
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                    {{ t('devices.section_label') }}
                </p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">
                    {{ t('devices.form.title_edit') }}
                </h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    {{ t('devices.form.subtitle_edit') }}
                </p>
            </header>

            <div v-if="generalError" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                {{ generalError }}
            </div>

            <div v-if="loading" class="rounded-lg border border-slate-200 bg-white px-4 py-6 text-sm text-slate-500">
                {{ t('common.loading') }}
            </div>

            <form v-else class="space-y-8" @submit.prevent="submit">
                <fieldset class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <legend class="px-2 text-sm font-semibold text-slate-700">
                        {{ t('devices.form.section_identity') }}
                    </legend>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('devices.fields.serial_number') }}</span>
                            <input
                                v-model="form.serial_number"
                                type="text"
                                required
                                class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                            >
                            <p v-if="fieldErrors.serial_number" class="mt-1 text-xs text-rose-600">{{ fieldErrors.serial_number[0] }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('devices.fields.kiosk_id') }}</span>
                            <input
                                v-model="form.kiosk_id"
                                type="text"
                                required
                                class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm font-mono focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                            >
                            <p v-if="fieldErrors.kiosk_id" class="mt-1 text-xs text-rose-600">{{ fieldErrors.kiosk_id[0] }}</p>
                            <p v-else class="mt-1 text-xs text-slate-500">{{ t('devices.form.kiosk_help') }}</p>
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('devices.fields.commission_profile') }} *</span>
                            <select
                                v-model.number="form.commission_profile_id"
                                required
                                class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                            >
                                <option :value="0" disabled>{{ t('devices.form.select_commission_profile') }}</option>
                                <option v-for="profile in commissionProfiles" :key="profile.id" :value="profile.id">{{ profile.name }}</option>
                            </select>
                            <p v-if="fieldErrors.commission_profile_id" class="mt-1 text-xs text-rose-600">{{ fieldErrors.commission_profile_id[0] }}</p>
                            <p v-else class="mt-1 text-xs text-slate-500">{{ t('devices.form.commission_profile_help') }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('devices.fields.organization') }} *</span>
                            <select
                                v-model.number="form.organization_id"
                                required
                                class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                            >
                                <option :value="0" disabled>{{ t('devices.form.select_organization') }}</option>
                                <option v-for="org in organizations" :key="org.id" :value="org.id">{{ org.name }}</option>
                            </select>
                            <p v-if="fieldErrors.organization_id" class="mt-1 text-xs text-rose-600">{{ fieldErrors.organization_id[0] }}</p>
                            <p v-else class="mt-1 text-xs text-slate-500">{{ t('devices.form.organization_help') }}</p>
                        </label>
                        <label class="block sm:col-span-2">
                            <span class="text-sm font-medium text-slate-700">{{ t('devices.fields.device_type') }}</span>
                            <select
                                v-model="form.device_type"
                                required
                                class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                            >
                                <option v-for="opt in typeOptions" :key="opt" :value="opt">
                                    {{ t(`devices.type_options.${opt}`) }}
                                </option>
                            </select>
                            <p v-if="fieldErrors.device_type" class="mt-1 text-xs text-rose-600">{{ fieldErrors.device_type[0] }}</p>
                            <p v-else class="mt-1 text-xs text-slate-500">{{ t('devices.form.type_help') }}</p>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <legend class="px-2 text-sm font-semibold text-slate-700">
                        {{ t('devices.form.section_hardware') }}
                    </legend>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('devices.fields.make') }} *</span>
                            <select
                                v-model.number="form.make_id"
                                required
                                class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                            >
                                <option :value="0" disabled>{{ t('devices.form.select_make') }}</option>
                                <option v-for="make in makes" :key="make.id" :value="make.id">{{ make.name }}</option>
                            </select>
                            <p v-if="fieldErrors.make_id" class="mt-1 text-xs text-rose-600">{{ fieldErrors.make_id[0] }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('devices.fields.model') }} *</span>
                            <select
                                v-model.number="form.model_id"
                                required
                                :disabled="!form.make_id || modelsLoading"
                                class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-50 disabled:text-slate-400"
                            >
                                <option :value="0" disabled>
                                    {{ modelsLoading ? t('common.loading') : (form.make_id ? t('devices.form.select_model') : t('devices.form.pick_make_first')) }}
                                </option>
                                <option v-for="model in modelsForSelectedMake" :key="model.id" :value="model.id">{{ model.name }}</option>
                            </select>
                            <p v-if="fieldErrors.model_id" class="mt-1 text-xs text-rose-600">{{ fieldErrors.model_id[0] }}</p>
                            <p v-else class="mt-1 text-xs text-slate-500">{{ t('devices.form.model_help') }}</p>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <legend class="px-2 text-sm font-semibold text-slate-700">
                        {{ t('devices.form.section_labels') }}
                    </legend>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('devices.fields.name') }}</span>
                            <input v-model="form.name" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('devices.fields.label') }}</span>
                            <input v-model="form.label" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p class="mt-1 text-xs text-slate-500">{{ t('devices.form.label_help') }}</p>
                        </label>
                    </div>
                </fieldset>

                <div class="flex items-center justify-end gap-3">
                    <RouterLink
                        :to="`/admin/devices/${uuid}`"
                        class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                    >
                        {{ t('devices.form.cancel') }}
                    </RouterLink>
                    <button
                        type="submit"
                        :disabled="submitting"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800 disabled:cursor-wait disabled:opacity-70"
                    >
                        {{ submitting ? t('devices.form.submitting') : t('devices.form.submit_edit') }}
                    </button>
                </div>
            </form>
        </section>
    </AdminLayout>
</template>
