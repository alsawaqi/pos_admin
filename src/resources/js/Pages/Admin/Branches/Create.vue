<script setup lang="ts">
import { ArrowLeft } from 'lucide-vue-next';
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRouter } from 'vue-router';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import MapPicker from '@/Components/Admin/MapPicker.vue';
import { createBranch, type BranchOrderType, type BranchStatus, type CreateBranchPayload } from '@/lib/api/branches';
import { listMerchants, type MerchantListItem } from '@/lib/api/merchants';

const { t } = useI18n();
const router = useRouter();

const merchants = ref<MerchantListItem[]>([]);
const submitting = ref(false);
const errors = ref<Record<string, string[]>>({});
const generalError = ref<string | null>(null);

const form = reactive<CreateBranchPayload>({
    company_id: 0,
    name: '',
    name_ar: '',
    code: '',
    manager_name: '',
    phone: '',
    email: '',
    address: '',
    latitude: 23.5859,
    longitude: 58.4059,
    geofence_radius_m: 500,
    default_order_type: 'quick',
    status: 'active',
});

const orderTypes: BranchOrderType[] = ['quick', 'dine_in', 'to_go', 'delivery', 'car'];
const statusValues: BranchStatus[] = ['active', 'inactive'];

const days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as const;
type DayKey = typeof days[number];
type DayHours = { open: string; close: string; closed: boolean };
const hours = reactive<Record<DayKey, DayHours>>({
    mon: { open: '09:00', close: '22:00', closed: false },
    tue: { open: '09:00', close: '22:00', closed: false },
    wed: { open: '09:00', close: '22:00', closed: false },
    thu: { open: '09:00', close: '22:00', closed: false },
    fri: { open: '09:00', close: '22:00', closed: false },
    sat: { open: '09:00', close: '22:00', closed: false },
    sun: { open: '09:00', close: '22:00', closed: false },
});

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
        const payload: CreateBranchPayload = {
            ...form,
            opening_hours_json: hours,
            name_ar: form.name_ar || null,
            code: form.code || null,
            manager_name: form.manager_name || null,
            phone: form.phone || null,
            email: form.email || null,
            address: form.address || null,
        };

        const response = await createBranch(payload);
        await router.push(`/admin/branches/${response.data.uuid}`);
    } catch (err: unknown) {
        if (err && typeof err === 'object' && 'status' in err && (err as { status: number }).status === 422) {
            const payload = (err as { payload?: { errors?: Record<string, string[]> } }).payload;
            errors.value = payload?.errors ?? {};
            generalError.value = t('branches.form.validation_summary');
        } else {
            generalError.value = err instanceof Error ? err.message : 'Failed to create branch';
        }
    } finally {
        submitting.value = false;
    }
}

onMounted(async () => {
    try {
        const response = await listMerchants({ per_page: 100 });
        merchants.value = response.data;
    } catch {
        merchants.value = [];
    }
});
</script>

<template>
    <AdminLayout>
        <section class="space-y-6">
            <RouterLink to="/admin/branches" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-slate-950">
                <ArrowLeft class="size-4" />
                {{ t('branches.back_to_list') }}
            </RouterLink>

            <header>
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                    {{ t('branches.section_label') }}
                </p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">{{ t('branches.form.title_create') }}</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">{{ t('branches.form.subtitle_create') }}</p>
            </header>

            <div
                v-if="generalError"
                class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700"
            >
                {{ generalError }}
            </div>

            <form class="space-y-8" @submit.prevent="submit">
                <fieldset class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <legend class="px-2 text-sm font-semibold text-slate-700">{{ t('branches.form.section_identity') }}</legend>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.company') }}</span>
                            <select
                                v-model.number="form.company_id"
                                required
                                class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                            >
                                <option :value="0" disabled>{{ t('branches.form.company_required') }}</option>
                                <option v-for="m in merchants" :key="m.id" :value="m.id">{{ m.name }}</option>
                            </select>
                            <p v-if="errors.company_id" class="mt-1 text-xs text-rose-600">{{ errors.company_id[0] }}</p>
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.code') }}</span>
                            <input v-model="form.code" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p v-if="errors.code" class="mt-1 text-xs text-rose-600">{{ errors.code[0] }}</p>
                        </label>

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
                            <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.manager_name') }}</span>
                            <input v-model="form.manager_name" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        </label>

                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.phone') }}</span>
                            <input v-model="form.phone" type="tel" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        </label>

                        <label class="block sm:col-span-2">
                            <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.email') }}</span>
                            <input v-model="form.email" type="email" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        </label>

                        <label class="block sm:col-span-2">
                            <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.address') }}</span>
                            <textarea v-model="form.address" rows="2" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" />
                        </label>
                    </div>
                </fieldset>

                <fieldset class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <legend class="px-2 text-sm font-semibold text-slate-700">{{ t('branches.form.section_location') }}</legend>

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
                            <p class="mt-1 text-xs text-slate-500">{{ t('branches.form.geofence_help') }}</p>
                            <p v-if="errors.geofence_radius_m" class="mt-1 text-xs text-rose-600">{{ errors.geofence_radius_m[0] }}</p>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <legend class="px-2 text-sm font-semibold text-slate-700">{{ t('branches.form.section_operations') }}</legend>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.default_order_type') }}</span>
                            <select v-model="form.default_order_type" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <option v-for="type in orderTypes" :key="type" :value="type">{{ t(`branches.order_types.${type}`) }}</option>
                            </select>
                            <p class="mt-1 text-xs text-slate-500">{{ t('branches.form.default_order_help') }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.status') }}</span>
                            <select v-model="form.status" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <option v-for="value in statusValues" :key="value" :value="value">{{ t(`branches.status_options.${value}`) }}</option>
                            </select>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                    <legend class="px-2 text-sm font-semibold text-slate-700">{{ t('branches.form.section_hours') }}</legend>
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

                <div class="flex items-center justify-end gap-3">
                    <RouterLink to="/admin/branches" class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        {{ t('branches.form.cancel') }}
                    </RouterLink>
                    <button
                        type="submit"
                        :disabled="submitting"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800 disabled:cursor-wait disabled:opacity-70"
                    >
                        {{ submitting ? t('branches.form.submitting') : t('branches.form.submit_create') }}
                    </button>
                </div>
            </form>
        </section>
    </AdminLayout>
</template>
