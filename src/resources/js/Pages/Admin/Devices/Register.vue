<script setup lang="ts">
/**
 * Register Device page — blueprint §4.4.2.
 *
 * Step 1 of the device lifecycle: admin enters the scalefusion kiosk
 * id (which they got from the scalefusion admin console after
 * enrolling the physical device), picks the hardware class, picks
 * the manufacturer and model from the catalogue, and optionally
 * fills the human-friendly label / display name.
 *
 * Make + Model are cascading dropdowns: the Model dropdown is
 * disabled and empty until a Make is chosen, then loads that
 * make's models. The back-end validates that the chosen model
 * belongs to the chosen make, so an admin can't sneak in a
 * mismatched pair via curl.
 *
 * The acquiring bank + bank-issued terminal id are deliberately NOT
 * entered here. They are tied to the merchant's bank account, so they
 * are captured later, when the device is ASSIGNED to a merchant's
 * branch (see the merchant view's AssignDeviceModal).
 *
 * After save, the user lands on the device detail page where they
 * can run step 2 (Assign) to bind the device to a company + branch.
 */

import { ArrowLeft } from 'lucide-vue-next';
import { onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRouter } from 'vue-router';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import {
    registerDevice,
    type DeviceType,
    type RegisterDevicePayload,
} from '@/lib/api/devices';
import { listMakes, listModels, type DeviceMake, type DeviceModel } from '@/lib/api/deviceCatalog';
// Read-only catalogue from the charity DB — populates the
// "Commission profile" dropdown.
import { listCommissionProfiles, type CommissionProfile } from '@/lib/api/commissionProfiles';

const { t } = useI18n();
const router = useRouter();

const submitting = ref(false);
const generalError = ref<string | null>(null);
// Laravel sends 422 errors keyed by field name with an array of
// messages. We surface the first message per field.
const fieldErrors = ref<Record<string, string[]>>({});

// Catalogue dropdown state — makes load once on mount; models reload
// whenever the user picks a different make.
const makes = ref<DeviceMake[]>([]);
const modelsForSelectedMake = ref<DeviceModel[]>([]);
const modelsLoading = ref(false);

// Commission profiles load once on mount — flat list from the shared
// charity DB. Active rows only by default (server-side filter).
const commissionProfiles = ref<CommissionProfile[]>([]);

// reactive() instead of ref({}) so v-model on each input is direct
// without `.value` plumbing. Defaults give the form a sane starting
// state — FixedPos is the most common class. make_id / model_id /
// commission_profile_id all start as 0 sentinels so the required
// validation is unambiguous (the option with value=0 is disabled, so
// the user must pick a real one).
const form = reactive<RegisterDevicePayload & {
    make_id: number;
    model_id: number;
    commission_profile_id: number;
}>({
    serial_number: '',
    kiosk_id: '',
    commission_profile_id: 0,
    device_type: 'fixed_pos',
    make_id: 0,
    model_id: 0,
    name: '',
    label: '',
});

const typeOptions: DeviceType[] = ['fixed_pos', 'handheld', 'customer_tablet'];

onMounted(async () => {
    try {
        // Load both reference lists in parallel — they're
        // independent and small.
        const [makesResponse, profilesResponse] = await Promise.all([
            listMakes(),
            listCommissionProfiles(),
        ]);
        makes.value = makesResponse.data;
        commissionProfiles.value = profilesResponse.data;
    } catch (err) {
        generalError.value = err instanceof Error
            ? `Failed to load reference data: ${err.message}`
            : 'Failed to load reference data';
    }
});

/**
 * Reload the Model dropdown whenever the chosen Make changes. Also
 * clear any previously-selected model so the form doesn't carry a
 * stale model_id from another make.
 */
watch(() => form.make_id, async (makeId) => {
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

/**
 * Submit the form. On 201 we redirect to the detail page so the
 * admin can immediately proceed to Assign (or just review what they
 * created). On 422 we keep the form values intact and highlight the
 * fields that failed.
 */
async function submit(): Promise<void> {
    submitting.value = true;
    generalError.value = null;
    fieldErrors.value = {};

    try {
        const response = await registerDevice({
            serial_number: form.serial_number,
            kiosk_id: form.kiosk_id,
            commission_profile_id: form.commission_profile_id,
            device_type: form.device_type,
            make_id: form.make_id,
            model_id: form.model_id,
            // Empty strings get coerced to null so the back-end's
            // nullable rules accept them without "field is invalid".
            name: form.name || null,
            label: form.label || null,
        });
        await router.push(`/admin/devices/${response.data.uuid}`);
    } catch (err: unknown) {
        if (err && typeof err === 'object' && 'status' in err && (err as { status: number }).status === 422) {
            const payload = (err as { payload?: { errors?: Record<string, string[]> } }).payload;
            fieldErrors.value = payload?.errors ?? {};
            generalError.value = t('devices.form.validation_summary');
        } else {
            generalError.value = err instanceof Error ? err.message : 'Failed to register device';
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <AdminLayout>
        <section class="mx-auto max-w-3xl space-y-6">
            <!-- Back link mirrors the Branches Show pattern. -->
            <RouterLink to="/admin/devices" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-slate-950">
                <ArrowLeft class="size-4" />
                {{ t('devices.back_to_list') }}
            </RouterLink>

            <header>
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                    {{ t('devices.section_label') }}
                </p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">
                    {{ t('devices.form.title_register') }}
                </h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    {{ t('devices.form.subtitle_register') }}
                </p>
            </header>

            <div v-if="generalError" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                {{ generalError }}
            </div>

            <!-- Helpful nudge when the catalogue is empty so admins
                 know where to add the missing data. -->
            <div
                v-if="makes.length === 0 && !generalError"
                class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900"
            >
                {{ t('devices.form.catalogue_empty_hint') }}
                <RouterLink to="/admin/settings/device-catalog" class="ms-1 font-semibold underline">
                    {{ t('nav.device_catalog') }}
                </RouterLink>
            </div>

            <form class="space-y-8" @submit.prevent="submit">
                <!-- Identity fieldset: serial + kiosk id are the two
                     hard requirements. Both are unique platform-wide. -->
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

                        <!-- Commission profile picker — sourced from
                             the shared charity DB. Required so every
                             new device has a donation-split rule
                             attached at creation time. -->
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

                <!-- Hardware fieldset: cascading Make → Model from the
                     reference catalogue. The Model dropdown stays
                     disabled until a Make is picked and only shows
                     models for that make. -->
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

                <!-- Optional labelling fieldset. None of these are
                     required by the back-end. -->
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
                        to="/admin/devices"
                        class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                    >
                        {{ t('devices.form.cancel') }}
                    </RouterLink>
                    <button
                        type="submit"
                        :disabled="submitting"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800 disabled:cursor-wait disabled:opacity-70"
                    >
                        {{ submitting ? t('devices.form.submitting') : t('devices.form.submit_register') }}
                    </button>
                </div>
            </form>
        </section>
    </AdminLayout>
</template>
