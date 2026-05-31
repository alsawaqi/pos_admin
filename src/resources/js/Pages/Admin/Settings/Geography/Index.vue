<script setup lang="ts">
import { Globe2, Pencil, Plus, Trash2, X } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { ApiError } from '@/lib/api';
import {
    createCity,
    createCountry,
    createDistrict,
    createRegion,
    deleteCity,
    deleteCountry,
    deleteDistrict,
    deleteRegion,
    listAllCities,
    listAllCountries,
    listAllDistricts,
    listAllRegions,
    updateCity,
    updateCountry,
    updateDistrict,
    updateRegion,
    type City,
    type Country,
    type District,
    type Region,
} from '@/lib/api/geography';
import { usePermissions } from '@/composables/usePermissions';
import { PlatformPermission } from '@/lib/permissions';

type Tab = 'countries' | 'regions' | 'districts' | 'cities';
type GeoRow = Country | Region | District | City;

const { t } = useI18n();
const { can } = usePermissions();
const canManage = computed(() => can(PlatformPermission.SettingsManage));

const tabs: Tab[] = ['countries', 'regions', 'districts', 'cities'];
const activeTab = ref<Tab>('countries');

const countries = ref<Country[]>([]);
const regions = ref<Region[]>([]);
const districts = ref<District[]>([]);
const cities = ref<City[]>([]);

// Parent filters: they scope each child list AND pre-fill the parent on create.
const selCountry = ref<number | null>(null);
const selRegion = ref<number | null>(null);
const selDistrict = ref<number | null>(null);

const loading = ref(false);
const error = ref<string | null>(null);
const flash = ref<{ type: 'success' | 'error'; text: string } | null>(null);

async function loadCountries(): Promise<void> {
    countries.value = (await listAllCountries({ include_inactive: true })).data;
}
async function loadRegions(): Promise<void> {
    regions.value = selCountry.value
        ? (await listAllRegions({ country_id: selCountry.value, include_inactive: true })).data
        : [];
}
async function loadDistricts(): Promise<void> {
    districts.value = selRegion.value
        ? (await listAllDistricts({ region_id: selRegion.value })).data
        : [];
}
async function loadCities(): Promise<void> {
    cities.value = selDistrict.value
        ? (await listAllCities({ district_id: selDistrict.value, include_inactive: true })).data
        : [];
}

watch(selCountry, async () => {
    selRegion.value = null;
    selDistrict.value = null;
    districts.value = [];
    cities.value = [];
    await loadRegions();
});
watch(selRegion, async () => {
    selDistrict.value = null;
    cities.value = [];
    await loadDistricts();
});
watch(selDistrict, async () => {
    await loadCities();
});

onMounted(async () => {
    loading.value = true;
    error.value = null;
    try {
        await loadCountries();
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load geography';
    } finally {
        loading.value = false;
    }
});

const currentRows = computed<GeoRow[]>(() => {
    if (activeTab.value === 'countries') return countries.value;
    if (activeTab.value === 'regions') return regions.value;
    if (activeTab.value === 'districts') return districts.value;
    return cities.value;
});

const needsParent = computed(() => {
    if (activeTab.value === 'regions') return !selCountry.value;
    if (activeTab.value === 'districts') return !selRegion.value;
    if (activeTab.value === 'cities') return !selDistrict.value;
    return false;
});

const canCreate = computed(() => canManage.value && !needsParent.value);

function rowInfo(row: GeoRow): string {
    if (activeTab.value === 'countries') {
        const c = row as Country;
        return [c.iso_code, c.phone_code].filter(Boolean).join(' · ');
    }
    if (activeTab.value === 'regions') {
        const r = row as Region;
        return [r.type, r.code].filter(Boolean).join(' · ');
    }
    if (activeTab.value === 'cities') {
        return (row as City).postal_code ?? '';
    }
    return '';
}

function rowActive(row: GeoRow): boolean | null {
    if (activeTab.value === 'districts') return null;
    return (row as { is_active: boolean }).is_active;
}

// ---- create / edit modal ----
const modalOpen = ref(false);
const modalMode = ref<'create' | 'edit'>('create');
const editingId = ref<number | null>(null);
const submitting = ref(false);
const fieldErrors = ref<Record<string, string[]>>({});
const modalError = ref<string | null>(null);

const form = reactive({
    name: '',
    iso_code: '',
    phone_code: '',
    type: '',
    code: '',
    postal_code: '',
    is_active: true,
});

function resetForm(): void {
    form.name = '';
    form.iso_code = '';
    form.phone_code = '';
    form.type = '';
    form.code = '';
    form.postal_code = '';
    form.is_active = true;
}

function openCreate(): void {
    modalMode.value = 'create';
    editingId.value = null;
    fieldErrors.value = {};
    modalError.value = null;
    resetForm();
    modalOpen.value = true;
}

function openEdit(row: GeoRow): void {
    modalMode.value = 'edit';
    editingId.value = row.id;
    fieldErrors.value = {};
    modalError.value = null;
    resetForm();
    form.name = row.name;
    if ('iso_code' in row) form.iso_code = row.iso_code;
    if ('phone_code' in row) form.phone_code = row.phone_code ?? '';
    if ('type' in row) form.type = row.type ?? '';
    if ('code' in row) form.code = row.code ?? '';
    if ('postal_code' in row) form.postal_code = row.postal_code ?? '';
    if ('is_active' in row) form.is_active = row.is_active;
    modalOpen.value = true;
}

function closeModal(): void {
    modalOpen.value = false;
}

async function submit(): Promise<void> {
    submitting.value = true;
    fieldErrors.value = {};
    modalError.value = null;
    const id = editingId.value;
    const creating = modalMode.value === 'create';
    try {
        if (activeTab.value === 'countries') {
            const payload = {
                name: form.name,
                iso_code: form.iso_code.toUpperCase(),
                phone_code: form.phone_code || null,
                is_active: form.is_active,
            };
            if (creating) await createCountry(payload);
            else if (id) await updateCountry(id, payload);
            await loadCountries();
        } else if (activeTab.value === 'regions') {
            const payload = {
                country_id: selCountry.value as number,
                name: form.name,
                type: form.type || null,
                code: form.code || null,
                is_active: form.is_active,
            };
            if (creating) await createRegion(payload);
            else if (id) await updateRegion(id, payload);
            await loadRegions();
        } else if (activeTab.value === 'districts') {
            const payload = { region_id: selRegion.value as number, name: form.name };
            if (creating) await createDistrict(payload);
            else if (id) await updateDistrict(id, payload);
            await loadDistricts();
        } else {
            const payload = {
                region_id: selRegion.value as number,
                district_id: selDistrict.value,
                name: form.name,
                postal_code: form.postal_code || null,
                is_active: form.is_active,
            };
            if (creating) await createCity(payload);
            else if (id) await updateCity(id, payload);
            await loadCities();
        }
        flash.value = { type: 'success', text: t('geography.flash.saved') };
        closeModal();
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            fieldErrors.value = err.payload.errors;
            modalError.value = t('geography.form.validation_summary');
        } else {
            modalError.value = err instanceof Error ? err.message : 'Save failed';
        }
    } finally {
        submitting.value = false;
    }
}

async function remove(row: GeoRow): Promise<void> {
    if (!window.confirm(t('geography.confirm_delete'))) return;
    try {
        if (activeTab.value === 'countries') {
            await deleteCountry(row.id);
            await loadCountries();
        } else if (activeTab.value === 'regions') {
            await deleteRegion(row.id);
            await loadRegions();
        } else if (activeTab.value === 'districts') {
            await deleteDistrict(row.id);
            await loadDistricts();
        } else {
            await deleteCity(row.id);
            await loadCities();
        }
        flash.value = { type: 'success', text: t('geography.flash.deleted') };
    } catch (err) {
        if (err instanceof ApiError && err.status === 409) {
            const message = (err.payload as { message?: string })?.message;
            flash.value = { type: 'error', text: message ?? t('geography.flash.delete_failed') };
        } else {
            flash.value = { type: 'error', text: err instanceof Error ? err.message : 'Delete failed' };
        }
    }
}
</script>

<template>
    <AdminLayout>
        <div class="space-y-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-950">{{ t('geography.list_title') }}</h1>
                    <p class="mt-1 max-w-2xl text-sm text-slate-500">{{ t('geography.list_subtitle') }}</p>
                </div>
                <button
                    v-if="canCreate"
                    type="button"
                    class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800"
                    @click="openCreate"
                >
                    <Plus class="size-4" />
                    {{ t(`geography.create.${activeTab}`) }}
                </button>
            </div>

            <div
                v-if="flash"
                :class="flash.type === 'success' ? 'border-teal-200 bg-teal-50 text-teal-700' : 'border-rose-200 bg-rose-50 text-rose-700'"
                class="rounded-lg border px-4 py-3 text-sm font-semibold"
            >
                {{ flash.text }}
            </div>

            <div class="flex gap-1 border-b border-slate-200">
                <button
                    v-for="tab in tabs"
                    :key="tab"
                    type="button"
                    :class="activeTab === tab ? 'border-slate-950 text-slate-950' : 'border-transparent text-slate-500 hover:text-slate-700'"
                    class="border-b-2 px-4 py-2.5 text-sm font-semibold transition"
                    @click="activeTab = tab"
                >
                    {{ t(`geography.tabs.${tab}`) }}
                </button>
            </div>

            <div v-if="activeTab !== 'countries'" class="flex flex-wrap gap-3">
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('geography.fields.country') }}</span>
                    <select v-model="selCountry" class="mt-1 w-56 rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <option :value="null">—</option>
                        <option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </label>
                <label v-if="activeTab === 'districts' || activeTab === 'cities'" class="block">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('geography.fields.region') }}</span>
                    <select v-model="selRegion" :disabled="!selCountry" class="mt-1 w-56 rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-50">
                        <option :value="null">—</option>
                        <option v-for="r in regions" :key="r.id" :value="r.id">{{ r.name }}</option>
                    </select>
                </label>
                <label v-if="activeTab === 'cities'" class="block">
                    <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('geography.fields.district') }}</span>
                    <select v-model="selDistrict" :disabled="!selRegion" class="mt-1 w-56 rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-50">
                        <option :value="null">—</option>
                        <option v-for="d in districts" :key="d.id" :value="d.id">{{ d.name }}</option>
                    </select>
                </label>
            </div>

            <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <div v-if="loading" class="p-8 text-center text-sm text-slate-500">{{ t('common.loading') }}</div>
                <div v-else-if="error" class="p-8 text-center text-sm text-rose-600">{{ error }}</div>
                <div v-else-if="needsParent" class="p-8 text-center text-sm text-slate-500">{{ t('geography.pick_parent') }}</div>
                <div v-else-if="currentRows.length === 0" class="p-10 text-center text-sm text-slate-500">
                    <Globe2 class="mx-auto mb-2 size-8 text-slate-300" />
                    {{ t('geography.empty') }}
                </div>
                <table v-else class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('geography.table.name') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('geography.table.info') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('geography.table.status') }}</th>
                            <th v-if="canManage" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('geography.table.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="row in currentRows" :key="row.id" class="hover:bg-slate-50">
                            <td class="px-4 py-3 text-sm font-medium text-slate-900">{{ row.name }}</td>
                            <td class="px-4 py-3 text-sm text-slate-500">{{ rowInfo(row) || '—' }}</td>
                            <td class="px-4 py-3 text-sm">
                                <span v-if="rowActive(row) === null" class="text-slate-400">—</span>
                                <span v-else-if="rowActive(row)" class="rounded-full bg-teal-50 px-2 py-0.5 text-xs font-semibold text-teal-700">{{ t('geography.status.active') }}</span>
                                <span v-else class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">{{ t('geography.status.inactive') }}</span>
                            </td>
                            <td v-if="canManage" class="px-4 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    <button type="button" class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-700" @click="openEdit(row)">
                                        <Pencil class="size-4" />
                                    </button>
                                    <button type="button" class="rounded-lg p-1.5 text-slate-500 hover:bg-rose-50 hover:text-rose-600" @click="remove(row)">
                                        <Trash2 class="size-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>

        <div
            v-if="modalOpen"
            class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/50 p-4 backdrop-blur-sm"
            @click.self="closeModal"
        >
            <div class="my-8 w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-950">
                        {{ modalMode === 'create' ? t(`geography.create.${activeTab}`) : t('geography.form.title_edit') }}
                    </h2>
                    <button type="button" class="rounded-lg p-1 text-slate-500 hover:bg-slate-100" @click="closeModal">
                        <X class="size-5" />
                    </button>
                </div>

                <div v-if="modalError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                    {{ modalError }}
                </div>

                <form class="mt-6 space-y-4" @submit.prevent="submit">
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">{{ t('geography.fields.name') }} *</span>
                        <input v-model="form.name" type="text" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p v-if="fieldErrors.name" class="mt-1 text-xs text-rose-600">{{ fieldErrors.name[0] }}</p>
                    </label>

                    <template v-if="activeTab === 'countries'">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('geography.fields.iso_code') }} *</span>
                            <input v-model="form.iso_code" type="text" maxlength="2" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm uppercase focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p v-if="fieldErrors.iso_code" class="mt-1 text-xs text-rose-600">{{ fieldErrors.iso_code[0] }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('geography.fields.phone_code') }}</span>
                            <input v-model="form.phone_code" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        </label>
                    </template>

                    <template v-else-if="activeTab === 'regions'">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('geography.fields.type') }}</span>
                            <input v-model="form.type" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('geography.fields.code') }}</span>
                            <input v-model="form.code" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        </label>
                    </template>

                    <template v-else-if="activeTab === 'cities'">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('geography.fields.postal_code') }}</span>
                            <input v-model="form.postal_code" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        </label>
                    </template>

                    <label v-if="activeTab !== 'districts'" class="inline-flex items-center gap-2">
                        <input v-model="form.is_active" type="checkbox" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                        <span class="text-sm font-medium text-slate-700">{{ t('geography.fields.is_active') }}</span>
                    </label>

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="closeModal">
                            {{ t('common.cancel') }}
                        </button>
                        <button
                            type="submit"
                            :disabled="submitting"
                            class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800 disabled:cursor-wait disabled:opacity-70"
                        >
                            {{ submitting ? t('common.saving') : t('common.save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
