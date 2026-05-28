<script setup lang="ts">
import { ArrowLeft, Pencil, Trash2, X } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import ConfirmDialog from '@/Components/Admin/ConfirmDialog.vue';
import MapPicker from '@/Components/Admin/MapPicker.vue';
import StatusPill, { type StatusTone } from '@/Components/Admin/StatusPill.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    deleteBranch,
    getBranch,
    updateBranch,
    type BranchDetail,
    type BranchOrderType,
    type BranchStatus,
    type UpdateBranchPayload,
} from '@/lib/api/branches';
import { PlatformPermission } from '@/lib/permissions';

const { t } = useI18n();
const { can } = usePermissions();
const route = useRoute();
const router = useRouter();

const branch = ref<BranchDetail | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const editing = ref(false);
const submitting = ref(false);
const fieldErrors = ref<Record<string, string[]>>({});
const editError = ref<string | null>(null);

// Delete-confirm state — separate from edit so the dialog can
// show even when the user clicked Edit by mistake first.
const deleteOpen = ref(false);
const deleting = ref(false);
const deleteError = ref<string | null>(null);

async function confirmDelete(): Promise<void> {
    if (!branch.value) {
        return;
    }
    deleting.value = true;
    deleteError.value = null;
    try {
        await deleteBranch(branch.value.uuid);
        deleteOpen.value = false;
        await router.push('/admin/branches');
    } catch (err) {
        if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            deleteError.value = String((err.payload as { message?: unknown }).message ?? 'Delete failed');
        } else {
            deleteError.value = err instanceof Error ? err.message : 'Delete failed';
        }
    } finally {
        deleting.value = false;
    }
}

const form = reactive<UpdateBranchPayload & { latitude: number; longitude: number; geofence_radius_m: number }>({
    name: '',
    name_ar: '',
    code: '',
    manager_name: '',
    phone: '',
    email: '',
    address: '',
    latitude: 0,
    longitude: 0,
    geofence_radius_m: 500,
    default_order_type: 'quick',
    status: 'active',
});

const mapValue = reactive({ latitude: 0, longitude: 0 });

const orderTypes: BranchOrderType[] = ['quick', 'dine_in', 'to_go', 'delivery', 'car'];
const statusValues: BranchStatus[] = ['active', 'inactive'];

function statusTone(value: BranchStatus | null): StatusTone {
    return value === 'active' ? 'green' : 'slate';
}

const statusLabel = computed(() => (branch.value?.status ? t(`branches.status_options.${branch.value.status}`) : '—'));

function hydrateForm(detail: BranchDetail): void {
    form.name = detail.name;
    form.name_ar = detail.name_ar ?? '';
    form.code = detail.code ?? '';
    form.manager_name = detail.manager_name ?? '';
    form.phone = detail.phone ?? '';
    form.email = detail.email ?? '';
    form.address = detail.address ?? '';
    form.latitude = detail.latitude ?? 23.5859;
    form.longitude = detail.longitude ?? 58.4059;
    form.geofence_radius_m = detail.geofence_radius_m;
    form.default_order_type = detail.default_order_type ?? 'quick';
    form.status = detail.status ?? 'active';
    mapValue.latitude = form.latitude;
    mapValue.longitude = form.longitude;
}

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const uuid = (route.params.uuid as string | undefined) ?? '';
        const response = await getBranch(uuid);
        branch.value = response.data;
        hydrateForm(response.data);
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load branch';
    } finally {
        loading.value = false;
    }
}

function onMapMove(value: { latitude: number; longitude: number }): void {
    form.latitude = value.latitude;
    form.longitude = value.longitude;
    mapValue.latitude = value.latitude;
    mapValue.longitude = value.longitude;
}

async function submitEdit(): Promise<void> {
    if (!branch.value) {
        return;
    }
    submitting.value = true;
    fieldErrors.value = {};
    editError.value = null;

    try {
        const payload: UpdateBranchPayload = {
            name: form.name,
            name_ar: form.name_ar || null,
            code: form.code || null,
            manager_name: form.manager_name || null,
            phone: form.phone || null,
            email: form.email || null,
            address: form.address || null,
            latitude: form.latitude,
            longitude: form.longitude,
            geofence_radius_m: form.geofence_radius_m,
            default_order_type: form.default_order_type,
            status: form.status,
        };

        const response = await updateBranch(branch.value.uuid, payload);
        branch.value = response.data;
        hydrateForm(response.data);
        editing.value = false;
    } catch (err: unknown) {
        if (err && typeof err === 'object' && 'status' in err && (err as { status: number }).status === 422) {
            const payload = (err as { payload?: { errors?: Record<string, string[]> } }).payload;
            fieldErrors.value = payload?.errors ?? {};
            editError.value = t('branches.form.validation_summary');
        } else {
            editError.value = err instanceof Error ? err.message : 'Failed to update branch';
        }
    } finally {
        submitting.value = false;
    }
}

function cancelEdit(): void {
    if (branch.value) {
        hydrateForm(branch.value);
    }
    fieldErrors.value = {};
    editError.value = null;
    editing.value = false;
}

watch(() => route.params.uuid, () => void load());

onMounted(() => void load());

void router; // keep import in case future actions push routes
</script>

<template>
    <AdminLayout>
        <section class="space-y-6">
            <RouterLink to="/admin/branches" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-600 hover:text-slate-950">
                <ArrowLeft class="size-4" />
                {{ t('branches.back_to_list') }}
            </RouterLink>

            <div v-if="loading" class="rounded-xl border border-slate-200 bg-white p-10 text-center text-sm font-medium text-slate-500 shadow-sm">
                {{ t('common.loading') }}
            </div>

            <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                {{ error }}
            </div>

            <template v-else-if="branch">
                <header class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">{{ branch.company?.name ?? '—' }}</p>
                        <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">{{ branch.name }}</h1>
                        <p v-if="branch.name_ar" class="mt-1 text-sm text-slate-500" dir="rtl">{{ branch.name_ar }}</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <StatusPill :label="statusLabel" :tone="statusTone(branch.status)" />
                        <button
                            v-if="can(PlatformPermission.BranchesUpdate) && !editing"
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800"
                            @click="editing = true"
                        >
                            <Pencil class="size-4" />
                            {{ t('branches.actions.edit') }}
                        </button>
                        <button
                            v-if="editing"
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                            @click="cancelEdit"
                        >
                            <X class="size-4" />
                            {{ t('branches.form.cancel') }}
                        </button>

                        <!-- Delete — destructive, only when not in
                             edit mode (avoid layout shift mid-edit).
                             Same soft-delete flow as the list page;
                             redirects back to the list on success. -->
                        <button
                            v-if="!editing && can(PlatformPermission.BranchesDelete)"
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-100"
                            @click="deleteOpen = true"
                        >
                            <Trash2 class="size-4" />
                            {{ t('common.delete') }}
                        </button>
                    </div>
                </header>

                <div v-if="editError" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                    {{ editError }}
                </div>

                <form v-if="editing" class="space-y-8" @submit.prevent="submitEdit">
                    <fieldset class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                        <legend class="px-2 text-sm font-semibold text-slate-700">{{ t('branches.form.section_identity') }}</legend>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.code') }}</span>
                                <input v-model="form.code" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <p v-if="fieldErrors.code" class="mt-1 text-xs text-rose-600">{{ fieldErrors.code[0] }}</p>
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.status') }}</span>
                                <select v-model="form.status" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                    <option v-for="value in statusValues" :key="value" :value="value">{{ t(`branches.status_options.${value}`) }}</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.name') }}</span>
                                <input v-model="form.name" type="text" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <p v-if="fieldErrors.name" class="mt-1 text-xs text-rose-600">{{ fieldErrors.name[0] }}</p>
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.name_ar') }}</span>
                                <input v-model="form.name_ar" type="text" dir="rtl" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
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
                        <MapPicker v-model="mapValue" :radius-meters="form.geofence_radius_m" @update:model-value="onMapMove" />
                        <div class="grid gap-4 sm:grid-cols-3">
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.latitude') }}</span>
                                <input v-model.number="form.latitude" type="number" step="0.0000001" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.longitude') }}</span>
                                <input v-model.number="form.longitude" type="number" step="0.0000001" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.geofence_radius_m') }}</span>
                                <input v-model.number="form.geofence_radius_m" type="number" min="100" max="2000" step="50" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            </label>
                        </div>
                    </fieldset>

                    <fieldset class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                        <legend class="px-2 text-sm font-semibold text-slate-700">{{ t('branches.form.section_operations') }}</legend>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('branches.fields.default_order_type') }}</span>
                            <select v-model="form.default_order_type" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <option v-for="type in orderTypes" :key="type" :value="type">{{ t(`branches.order_types.${type}`) }}</option>
                            </select>
                        </label>
                    </fieldset>

                    <div class="flex items-center justify-end gap-3">
                        <button type="submit" :disabled="submitting" class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800 disabled:cursor-wait disabled:opacity-70">
                            {{ submitting ? t('branches.form.submitting') : t('branches.form.submit_update') }}
                        </button>
                    </div>
                </form>

                <template v-else>
                    <div class="grid gap-6 lg:grid-cols-[2fr_1fr]">
                        <div class="space-y-6">
                            <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ t('branches.overview.location') }}</h2>
                                <div class="mt-4">
                                    <MapPicker
                                        :model-value="{ latitude: branch.latitude, longitude: branch.longitude }"
                                        :radius-meters="branch.geofence_radius_m"
                                        height="320px"
                                    />
                                </div>
                                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                                    <div>
                                        <dt class="font-medium text-slate-500">{{ t('branches.fields.latitude') }}</dt>
                                        <dd class="font-semibold text-slate-900">{{ branch.latitude ?? '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="font-medium text-slate-500">{{ t('branches.fields.longitude') }}</dt>
                                        <dd class="font-semibold text-slate-900">{{ branch.longitude ?? '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="font-medium text-slate-500">{{ t('branches.fields.geofence_radius_m') }}</dt>
                                        <dd class="font-semibold text-slate-900">{{ branch.geofence_radius_m }} m</dd>
                                    </div>
                                </dl>
                                <p v-if="branch.address" class="mt-4 text-sm text-slate-600">{{ branch.address }}</p>
                            </section>

                            <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ t('branches.overview.identity') }}</h2>
                                <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                                    <div>
                                        <dt class="font-medium text-slate-500">{{ t('branches.fields.code') }}</dt>
                                        <dd class="font-semibold text-slate-900">{{ branch.code ?? '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="font-medium text-slate-500">{{ t('branches.fields.manager_name') }}</dt>
                                        <dd class="font-semibold text-slate-900">{{ branch.manager_name ?? '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="font-medium text-slate-500">{{ t('branches.fields.phone') }}</dt>
                                        <dd class="font-semibold text-slate-900">{{ branch.phone ?? '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="font-medium text-slate-500">{{ t('branches.fields.email') }}</dt>
                                        <dd class="font-semibold text-slate-900">{{ branch.email ?? '—' }}</dd>
                                    </div>
                                </dl>
                            </section>
                        </div>

                        <aside class="space-y-6">
                            <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ t('branches.overview.operations') }}</h2>
                                <dl class="mt-4 space-y-3 text-sm">
                                    <div>
                                        <dt class="font-medium text-slate-500">{{ t('branches.fields.default_order_type') }}</dt>
                                        <dd class="font-semibold text-slate-900">
                                            {{ branch.default_order_type ? t(`branches.order_types.${branch.default_order_type}`) : '—' }}
                                        </dd>
                                    </div>
                                </dl>
                            </section>

                            <section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ t('branches.overview.devices') }}</h2>
                                <p class="mt-2 text-2xl font-semibold text-slate-950">{{ branch.devices_count ?? 0 }}</p>
                                <p class="text-sm text-slate-500">{{ t('branches.overview.devices_count', { count: branch.devices_count ?? 0 }) }}</p>
                            </section>
                        </aside>
                    </div>
                </template>
            </template>
        </section>

        <ConfirmDialog
            v-if="deleteOpen && branch"
            :title="t('branches.delete.title')"
            :message="t('branches.delete.message', { name: branch.name })"
            :confirm-label="t('common.delete')"
            :loading="deleting"
            :error="deleteError"
            @confirm="confirmDelete"
            @cancel="deleteOpen = false"
        />
    </AdminLayout>
</template>
