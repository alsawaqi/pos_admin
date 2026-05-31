<script setup lang="ts">
import { X } from 'lucide-vue-next';
import { onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import MapPicker from '@/Components/Admin/MapPicker.vue';
import {
    createBranch,
    updateBranch,
    type BranchListItem,
    type BranchOrderType,
    type BranchStatus,
    type CreateBranchPayload,
    type UpdateBranchPayload,
} from '@/lib/api/branches';
import {
    listAllCities,
    listAllCountries,
    listAllDistricts,
    listAllRegions,
    type City,
    type Country,
    type District,
    type Region,
} from '@/lib/api/geography';

const props = defineProps<{
    companyId: number;
    branch?: BranchListItem | null;
}>();

const emit = defineEmits<{
    (e: 'saved'): void;
    (e: 'close'): void;
}>();

const { t } = useI18n();

const isEdit = !!props.branch;

const submitting = ref(false);
const errors = ref<Record<string, string[]>>({});
const generalError = ref<string | null>(null);

// Identity + location + operations fields. company_id is fixed by the
// merchant being viewed, so there is no company picker here.
const form = reactive({
    name: props.branch?.name ?? '',
    name_ar: props.branch?.name_ar ?? '',
    code: props.branch?.code ?? '',
    manager_name: props.branch?.manager_name ?? '',
    phone: props.branch?.phone ?? '',
    email: props.branch?.email ?? '',
    address: props.branch?.address ?? '',
    country_id: props.branch?.country_id ?? null,
    region_id: props.branch?.region_id ?? null,
    district_id: props.branch?.district_id ?? null,
    city_id: props.branch?.city_id ?? null,
    latitude: props.branch?.latitude ?? 23.5859,
    longitude: props.branch?.longitude ?? 58.4059,
    geofence_radius_m: props.branch?.geofence_radius_m ?? 500,
    default_order_type: (props.branch?.default_order_type ?? 'quick') as BranchOrderType,
    status: (props.branch?.status ?? 'active') as BranchStatus,
});

const orderTypes: BranchOrderType[] = ['quick', 'dine_in', 'to_go', 'delivery', 'car'];
const statusValues: BranchStatus[] = ['active', 'inactive'];

// Geo cascade (country -> region -> district -> city). Option lists load
// lazily: countries on mount, then each child list whenever its parent id
// is set. Changing a parent clears its descendants.
const countries = ref<Country[]>([]);
const regions = ref<Region[]>([]);
const districts = ref<District[]>([]);
const cities = ref<City[]>([]);

async function loadRegions(countryId: number): Promise<void> {
    regions.value = (await listAllRegions({ country_id: countryId })).data;
}

async function loadDistricts(regionId: number): Promise<void> {
    districts.value = (await listAllDistricts({ region_id: regionId })).data;
}

async function loadCities(districtId: number): Promise<void> {
    cities.value = (await listAllCities({ district_id: districtId })).data;
}

watch(() => form.country_id, async (countryId) => {
    form.region_id = null;
    form.district_id = null;
    form.city_id = null;
    regions.value = [];
    districts.value = [];
    cities.value = [];
    if (countryId) {
        await loadRegions(countryId);
    }
});

watch(() => form.region_id, async (regionId) => {
    form.district_id = null;
    form.city_id = null;
    districts.value = [];
    cities.value = [];
    if (regionId) {
        await loadDistricts(regionId);
    }
});

watch(() => form.district_id, async (districtId) => {
    form.city_id = null;
    cities.value = [];
    if (districtId) {
        await loadCities(districtId);
    }
});

const days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as const;
type DayKey = typeof days[number];
type DayHours = { open: string; close: string; closed: boolean };

function defaultHours(): Record<DayKey, DayHours> {
    return {
        mon: { open: '09:00', close: '22:00', closed: false },
        tue: { open: '09:00', close: '22:00', closed: false },
        wed: { open: '09:00', close: '22:00', closed: false },
        thu: { open: '09:00', close: '22:00', closed: false },
        fri: { open: '09:00', close: '22:00', closed: false },
        sat: { open: '09:00', close: '22:00', closed: false },
        sun: { open: '09:00', close: '22:00', closed: false },
    };
}

// Seed the grid from the branch's stored hours in edit mode, falling
// back to the 09:00-22:00 defaults for any missing/partial day.
const hours = reactive<Record<DayKey, DayHours>>(defaultHours());
if (props.branch?.opening_hours_json) {
    const stored = props.branch.opening_hours_json;
    for (const day of days) {
        const entry = stored[day];
        if (entry) {
            hours[day] = {
                open: entry.open ?? '09:00',
                close: entry.close ?? '22:00',
                closed: entry.closed ?? false,
            };
        }
    }
}

const mapValue = reactive({ latitude: form.latitude, longitude: form.longitude });

function onMapMove(value: { latitude: number; longitude: number }): void {
    form.latitude = value.latitude;
    form.longitude = value.longitude;
    mapValue.latitude = value.latitude;
    mapValue.longitude = value.longitude;
}

function onLatLngInput(): void {
    if (typeof form.latitude === 'number' && typeof form.longitude === 'number') {
        mapValue.latitude = form.latitude;
        mapValue.longitude = form.longitude;
    }
}

async function submit(): Promise<void> {
    submitting.value = true;
    errors.value = {};
    generalError.value = null;

    try {
        if (isEdit && props.branch) {
            const payload: UpdateBranchPayload = {
                name: form.name,
                name_ar: form.name_ar || null,
                code: form.code || null,
                manager_name: form.manager_name || null,
                phone: form.phone || null,
                email: form.email || null,
                address: form.address || null,
                country_id: form.country_id,
                region_id: form.region_id,
                district_id: form.district_id,
                city_id: form.city_id,
                latitude: form.latitude,
                longitude: form.longitude,
                geofence_radius_m: form.geofence_radius_m,
                default_order_type: form.default_order_type,
                status: form.status,
                opening_hours_json: hours,
            };
            await updateBranch(props.branch.uuid, payload);
        } else {
            const payload: CreateBranchPayload = {
                company_id: props.companyId,
                name: form.name,
                name_ar: form.name_ar || null,
                code: form.code || null,
                manager_name: form.manager_name || null,
                phone: form.phone || null,
                email: form.email || null,
                address: form.address || null,
                country_id: form.country_id,
                region_id: form.region_id,
                district_id: form.district_id,
                city_id: form.city_id,
                latitude: form.latitude,
                longitude: form.longitude,
                geofence_radius_m: form.geofence_radius_m,
                default_order_type: form.default_order_type,
                status: form.status,
                opening_hours_json: hours,
            };
            await createBranch(payload);
        }
        emit('saved');
    } catch (err: unknown) {
        if (err && typeof err === 'object' && 'status' in err && (err as { status: number }).status === 422) {
            const payload = (err as { payload?: { errors?: Record<string, string[]> } }).payload;
            errors.value = payload?.errors ?? {};
            generalError.value = t('branches.form.validation_summary');
        } else {
            generalError.value = err instanceof Error ? err.message : t('common.error');
        }
    } finally {
        submitting.value = false;
    }
}

onMounted(async () => {
    onLatLngInput();
    countries.value = (await listAllCountries()).data;
    if (form.country_id) {
        await loadRegions(form.country_id);
    }
    if (form.region_id) {
        await loadDistricts(form.region_id);
    }
    if (form.district_id) {
        await loadCities(form.district_id);
    }
});
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/40 p-4"
        @click.self="emit('close')"
    >
        <div class="my-8 flex w-full max-w-2xl flex-col rounded-2xl bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-slate-100 px-6 py-4">
                <h2 class="text-lg font-semibold text-slate-950">
                    {{ isEdit ? t('merchants.branches.edit_title') : t('merchants.branches.new') }}
                </h2>
                <button type="button" class="text-slate-400 hover:text-slate-600" @click="emit('close')">
                    <X class="size-5" />
                </button>
            </div>

            <form class="flex-1 space-y-6 overflow-y-auto px-6 py-5" @submit.prevent="submit">
                <div
                    v-if="generalError"
                    class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700"
                >
                    {{ generalError }}
                </div>

                <fieldset class="space-y-4">
                    <legend class="text-sm font-semibold text-slate-700">{{ t('branches.form.section_identity') }}</legend>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.name') }}</span>
                            <input v-model="form.name" type="text" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p v-if="errors.name" class="mt-1 text-xs text-rose-600">{{ errors.name[0] }}</p>
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.name_ar') }}</span>
                            <input v-model="form.name_ar" type="text" dir="rtl" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p v-if="errors.name_ar" class="mt-1 text-xs text-rose-600">{{ errors.name_ar[0] }}</p>
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.code') }}</span>
                            <input v-model="form.code" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p v-if="errors.code" class="mt-1 text-xs text-rose-600">{{ errors.code[0] }}</p>
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.manager_name') }}</span>
                            <input v-model="form.manager_name" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p v-if="errors.manager_name" class="mt-1 text-xs text-rose-600">{{ errors.manager_name[0] }}</p>
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.phone') }}</span>
                            <input v-model="form.phone" type="tel" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p v-if="errors.phone" class="mt-1 text-xs text-rose-600">{{ errors.phone[0] }}</p>
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.email') }}</span>
                            <input v-model="form.email" type="email" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p v-if="errors.email" class="mt-1 text-xs text-rose-600">{{ errors.email[0] }}</p>
                        </label>

                        <label class="block sm:col-span-2">
                            <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.address') }}</span>
                            <textarea v-model="form.address" rows="2" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" />
                            <p v-if="errors.address" class="mt-1 text-xs text-rose-600">{{ errors.address[0] }}</p>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="space-y-4">
                    <legend class="text-sm font-semibold text-slate-700">{{ t('branches.form.section_location') }}</legend>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.country') }}</span>
                            <select v-model="form.country_id" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <option :value="null">—</option>
                                <option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                            <p v-if="errors.country_id" class="mt-1 text-xs text-rose-600">{{ errors.country_id[0] }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.region') }}</span>
                            <select v-model="form.region_id" :disabled="!form.country_id" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-50">
                                <option :value="null">—</option>
                                <option v-for="r in regions" :key="r.id" :value="r.id">{{ r.name }}</option>
                            </select>
                            <p v-if="errors.region_id" class="mt-1 text-xs text-rose-600">{{ errors.region_id[0] }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.district') }}</span>
                            <select v-model="form.district_id" :disabled="!form.region_id" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-50">
                                <option :value="null">—</option>
                                <option v-for="d in districts" :key="d.id" :value="d.id">{{ d.name }}</option>
                            </select>
                            <p v-if="errors.district_id" class="mt-1 text-xs text-rose-600">{{ errors.district_id[0] }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.city') }}</span>
                            <select v-model="form.city_id" :disabled="!form.district_id" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-50">
                                <option :value="null">—</option>
                                <option v-for="ct in cities" :key="ct.id" :value="ct.id">{{ ct.name }}</option>
                            </select>
                            <p v-if="errors.city_id" class="mt-1 text-xs text-rose-600">{{ errors.city_id[0] }}</p>
                        </label>
                    </div>

                    <MapPicker
                        v-model="mapValue"
                        :radius-meters="form.geofence_radius_m ?? 500"
                        @update:model-value="onMapMove"
                    />

                    <div class="grid gap-4 sm:grid-cols-3">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.latitude') }}</span>
                            <input v-model.number="form.latitude" type="number" step="0.0000001" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" @input="onLatLngInput">
                            <p v-if="errors.latitude" class="mt-1 text-xs text-rose-600">{{ errors.latitude[0] }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.longitude') }}</span>
                            <input v-model.number="form.longitude" type="number" step="0.0000001" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" @input="onLatLngInput">
                            <p v-if="errors.longitude" class="mt-1 text-xs text-rose-600">{{ errors.longitude[0] }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.geofence_radius_m') }}</span>
                            <input v-model.number="form.geofence_radius_m" type="number" min="100" max="2000" step="50" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p v-if="errors.geofence_radius_m" class="mt-1 text-xs text-rose-600">{{ errors.geofence_radius_m[0] }}</p>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="space-y-4">
                    <legend class="text-sm font-semibold text-slate-700">{{ t('branches.form.section_operations') }}</legend>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.default_order_type') }}</span>
                            <select v-model="form.default_order_type" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <option v-for="type in orderTypes" :key="type" :value="type">{{ t(`branches.order_types.${type}`) }}</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.status') }}</span>
                            <select v-model="form.status" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <option v-for="value in statusValues" :key="value" :value="value">{{ t(`branches.status_options.${value}`) }}</option>
                            </select>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="space-y-4">
                    <legend class="text-sm font-semibold text-slate-700">{{ t('branches.form.section_hours') }}</legend>
                    <div class="space-y-2">
                        <div v-for="day in days" :key="day" class="grid items-center gap-3 sm:grid-cols-[80px_1fr_1fr_auto]">
                            <span class="text-sm font-semibold text-slate-700">{{ t(`branches.days.${day}`) }}</span>
                            <input v-model="hours[day].open" type="time" :disabled="hours[day].closed" class="rounded-lg border border-slate-200 px-3 py-2 text-sm disabled:bg-slate-50">
                            <input v-model="hours[day].close" type="time" :disabled="hours[day].closed" class="rounded-lg border border-slate-200 px-3 py-2 text-sm disabled:bg-slate-50">
                            <label class="inline-flex items-center gap-2 text-xs font-semibold text-slate-500">
                                <input v-model="hours[day].closed" type="checkbox" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                                {{ t('branches.hours.closed') }}
                            </label>
                        </div>
                    </div>
                </fieldset>
            </form>

            <div class="flex items-center justify-end gap-3 border-t border-slate-100 px-6 py-4">
                <button
                    type="button"
                    class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                    @click="emit('close')"
                >
                    {{ t('common.cancel') }}
                </button>
                <button
                    type="button"
                    :disabled="submitting"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800 disabled:cursor-wait disabled:opacity-70"
                    @click="submit"
                >
                    {{ submitting ? t('common.saving') : t('common.save') }}
                </button>
            </div>
        </div>
    </div>
</template>
