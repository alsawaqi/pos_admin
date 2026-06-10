<script setup lang="ts">
/**
 * Settings → Device catalogue — master/detail admin page for the
 * manufacturers (makes) and the models they offer. Drives the
 * cascading dropdowns on the Register Device page.
 *
 * Layout:
 *   - Left pane: list of makes. Click a row to "focus" it; the
 *     right pane filters to that make's models.
 *   - Right pane: models for the currently-focused make. Empty
 *     state when nothing is focused yet.
 *   - Each pane has its own +/edit/delete modal + "Show inactive"
 *     toggle + delete-blocked-by-409 banner.
 *
 * Behaviour notes:
 *   - Deleting a make/model that is still in use by a device
 *     returns 409 with a human-readable message. Surfaced as a
 *     toast-style banner.
 *   - Deleting a make that still has child models also returns
 *     409 — admin should delete the models first or deactivate the
 *     make instead.
 *   - "Toggle active" is a one-click PATCH on each row's status.
 */

import { ChevronRight, MonitorSmartphone, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import BaseModal from '@/Components/BaseModal.vue';
import { ApiError } from '@/lib/api';
import {
    createMake,
    createModel,
    deleteMake,
    deleteModel,
    listMakes,
    listModels,
    updateMake,
    updateModel,
    type DeviceMake,
    type DeviceMakePayload,
    type DeviceModel,
    type DeviceModelPayload,
} from '@/lib/api/deviceCatalog';
import { usePermissions } from '@/composables/usePermissions';
import { PlatformPermission } from '@/lib/permissions';

const { t } = useI18n();
const { can } = usePermissions();
const canManage = computed(() => can(PlatformPermission.DeviceModelsManage));

// ---- Page state ----------------------------------------------------
const makes = ref<DeviceMake[]>([]);
const makesLoading = ref(true);
const makesError = ref<string | null>(null);
const showInactiveMakes = ref(false);

// "Currently focused" make — the right pane shows its models.
const focusedMake = ref<DeviceMake | null>(null);
const models = ref<DeviceModel[]>([]);
const modelsLoading = ref(false);
const modelsError = ref<string | null>(null);
const showInactiveModels = ref(false);

// Toast-style flash for the last action result.
const flash = ref<{ type: 'success' | 'error'; text: string } | null>(null);

// ---- Make modal state ---------------------------------------------
const makeModalOpen = ref(false);
const makeModalMode = ref<'create' | 'edit'>('create');
const editingMakeId = ref<number | null>(null);
const makeSubmitting = ref(false);
const makeFieldErrors = ref<Record<string, string[]>>({});
const makeModalError = ref<string | null>(null);
const makeForm = reactive<DeviceMakePayload>({
    name: '',
    display_order: 0,
    is_active: true,
});

// ---- Model modal state --------------------------------------------
const modelModalOpen = ref(false);
const modelModalMode = ref<'create' | 'edit'>('create');
const editingModelId = ref<number | null>(null);
const modelSubmitting = ref(false);
const modelFieldErrors = ref<Record<string, string[]>>({});
const modelModalError = ref<string | null>(null);
const modelForm = reactive<DeviceModelPayload>({
    name: '',
    code: '',
    display_order: 0,
    is_active: true,
});

// ---- Loaders ------------------------------------------------------
async function loadMakes(): Promise<void> {
    makesLoading.value = true;
    makesError.value = null;
    try {
        const response = await listMakes({ include_inactive: showInactiveMakes.value || undefined });
        makes.value = response.data;
        // If the focused make got deactivated and we're hiding
        // inactives, drop the focus so we don't show stale models.
        if (focusedMake.value && !makes.value.some((m) => m.id === focusedMake.value!.id)) {
            focusedMake.value = null;
            models.value = [];
        }
    } catch (err) {
        makesError.value = err instanceof Error ? err.message : 'Failed to load makes';
    } finally {
        makesLoading.value = false;
    }
}

async function loadModels(): Promise<void> {
    if (!focusedMake.value) {
        models.value = [];

        return;
    }
    modelsLoading.value = true;
    modelsError.value = null;
    try {
        const response = await listModels(focusedMake.value.id, {
            include_inactive: showInactiveModels.value || undefined,
        });
        models.value = response.data;
    } catch (err) {
        modelsError.value = err instanceof Error ? err.message : 'Failed to load models';
    } finally {
        modelsLoading.value = false;
    }
}

watch(showInactiveMakes, () => void loadMakes());
watch(showInactiveModels, () => void loadModels());
watch(focusedMake, () => void loadModels());

onMounted(() => void loadMakes());

// ---- Make CRUD ----------------------------------------------------
function openCreateMake(): void {
    makeModalMode.value = 'create';
    editingMakeId.value = null;
    makeForm.name = '';
    makeForm.display_order = 0;
    makeForm.is_active = true;
    makeFieldErrors.value = {};
    makeModalError.value = null;
    makeModalOpen.value = true;
}

function openEditMake(make: DeviceMake): void {
    makeModalMode.value = 'edit';
    editingMakeId.value = make.id;
    makeForm.name = make.name;
    makeForm.display_order = make.display_order;
    makeForm.is_active = make.is_active;
    makeFieldErrors.value = {};
    makeModalError.value = null;
    makeModalOpen.value = true;
}

async function submitMake(): Promise<void> {
    makeSubmitting.value = true;
    makeFieldErrors.value = {};
    makeModalError.value = null;
    try {
        if (makeModalMode.value === 'create') {
            await createMake(makeForm);
            flash.value = { type: 'success', text: t('device_catalog.flash.make_created') };
        } else if (editingMakeId.value !== null) {
            await updateMake(editingMakeId.value, makeForm);
            flash.value = { type: 'success', text: t('device_catalog.flash.make_updated') };
        }
        makeModalOpen.value = false;
        await loadMakes();
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            makeFieldErrors.value = err.payload.errors;
            makeModalError.value = t('device_catalog.form.validation_summary');
        } else {
            makeModalError.value = err instanceof Error ? err.message : 'Save failed';
        }
    } finally {
        makeSubmitting.value = false;
    }
}

async function toggleMakeActive(make: DeviceMake): Promise<void> {
    try {
        await updateMake(make.id, { is_active: !make.is_active });
        flash.value = { type: 'success', text: t('device_catalog.flash.toggled') };
        await loadMakes();
    } catch (err) {
        flash.value = { type: 'error', text: err instanceof Error ? err.message : 'Toggle failed' };
    }
}

async function removeMake(make: DeviceMake): Promise<void> {
    if (!window.confirm(t('device_catalog.confirm_delete_make'))) {
        return;
    }
    try {
        await deleteMake(make.id);
        flash.value = { type: 'success', text: t('device_catalog.flash.make_deleted') };
        if (focusedMake.value?.id === make.id) {
            focusedMake.value = null;
        }
        await loadMakes();
    } catch (err) {
        // 409 — make still in use by devices OR still has child models.
        const conflict = err instanceof ApiError && err.status === 409
            ? (err.payload as { message?: unknown } | null)?.message
            : undefined;
        if (typeof conflict === 'string' && conflict) {
            flash.value = { type: 'error', text: conflict };
        } else {
            flash.value = { type: 'error', text: err instanceof Error ? err.message : 'Delete failed' };
        }
    }
}

// ---- Model CRUD ---------------------------------------------------
function openCreateModel(): void {
    if (!focusedMake.value) {
        return;
    }
    modelModalMode.value = 'create';
    editingModelId.value = null;
    modelForm.name = '';
    modelForm.code = '';
    modelForm.display_order = 0;
    modelForm.is_active = true;
    modelFieldErrors.value = {};
    modelModalError.value = null;
    modelModalOpen.value = true;
}

function openEditModel(model: DeviceModel): void {
    modelModalMode.value = 'edit';
    editingModelId.value = model.id;
    modelForm.name = model.name;
    modelForm.code = model.code ?? '';
    modelForm.display_order = model.display_order;
    modelForm.is_active = model.is_active;
    modelFieldErrors.value = {};
    modelModalError.value = null;
    modelModalOpen.value = true;
}

async function submitModel(): Promise<void> {
    if (!focusedMake.value) {
        return;
    }
    modelSubmitting.value = true;
    modelFieldErrors.value = {};
    modelModalError.value = null;
    // Normalise empty code → null so the nullable validator passes.
    const payload: DeviceModelPayload = {
        ...modelForm,
        code: modelForm.code || null,
    };
    try {
        if (modelModalMode.value === 'create') {
            await createModel(focusedMake.value.id, payload);
            flash.value = { type: 'success', text: t('device_catalog.flash.model_created') };
        } else if (editingModelId.value !== null) {
            await updateModel(focusedMake.value.id, editingModelId.value, payload);
            flash.value = { type: 'success', text: t('device_catalog.flash.model_updated') };
        }
        modelModalOpen.value = false;
        await loadModels();
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            modelFieldErrors.value = err.payload.errors;
            modelModalError.value = t('device_catalog.form.validation_summary');
        } else {
            modelModalError.value = err instanceof Error ? err.message : 'Save failed';
        }
    } finally {
        modelSubmitting.value = false;
    }
}

async function toggleModelActive(model: DeviceModel): Promise<void> {
    if (!focusedMake.value) {
        return;
    }
    try {
        await updateModel(focusedMake.value.id, model.id, { is_active: !model.is_active });
        flash.value = { type: 'success', text: t('device_catalog.flash.toggled') };
        await loadModels();
    } catch (err) {
        flash.value = { type: 'error', text: err instanceof Error ? err.message : 'Toggle failed' };
    }
}

async function removeModel(model: DeviceModel): Promise<void> {
    if (!focusedMake.value) {
        return;
    }
    if (!window.confirm(t('device_catalog.confirm_delete_model'))) {
        return;
    }
    try {
        await deleteModel(focusedMake.value.id, model.id);
        flash.value = { type: 'success', text: t('device_catalog.flash.model_deleted') };
        await loadModels();
    } catch (err) {
        const conflict = err instanceof ApiError && err.status === 409
            ? (err.payload as { message?: unknown } | null)?.message
            : undefined;
        if (typeof conflict === 'string' && conflict) {
            flash.value = { type: 'error', text: conflict };
        } else {
            flash.value = { type: 'error', text: err instanceof Error ? err.message : 'Delete failed' };
        }
    }
}
</script>

<template>
    <AdminLayout>
        <section class="space-y-6">
            <!-- Header -->
            <div>
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                    {{ t('device_catalog.section_label') }}
                </p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">
                    {{ t('device_catalog.title') }}
                </h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                    {{ t('device_catalog.subtitle') }}
                </p>
            </div>

            <!-- Flash banner -->
            <div
                v-if="flash"
                class="rounded-lg border px-4 py-3 text-sm font-semibold"
                :class="flash.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-700'"
            >
                {{ flash.text }}
            </div>

            <!-- Master / detail layout -->
            <div class="grid gap-6 lg:grid-cols-5">
                <!-- =========================================================
                     MAKES PANE (left, 2 columns wide on lg)
                     ======================================================== -->
                <section class="lg:col-span-2 space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                                {{ t('device_catalog.makes.title') }}
                            </h2>
                            <p class="mt-1 text-xs text-slate-500">{{ t('device_catalog.makes.subtitle') }}</p>
                        </div>
                        <button
                            v-if="canManage"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-lg bg-slate-950 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800"
                            @click="openCreateMake"
                        >
                            <Plus class="size-3.5" />
                            {{ t('device_catalog.makes.new') }}
                        </button>
                    </div>

                    <label class="flex items-center gap-2 text-xs text-slate-600">
                        <input v-model="showInactiveMakes" type="checkbox" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                        {{ t('device_catalog.show_inactive') }}
                    </label>

                    <div v-if="makesError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700">
                        {{ makesError }}
                    </div>

                    <div v-if="makesLoading" class="p-6 text-center text-xs text-slate-500">
                        {{ t('common.loading') }}
                    </div>

                    <div v-else-if="makes.length === 0" class="flex flex-col items-center gap-2 rounded-lg border border-dashed border-slate-200 p-6 text-center text-xs text-slate-500">
                        <MonitorSmartphone class="size-6 text-slate-300" />
                        <p>{{ t('device_catalog.makes.empty') }}</p>
                    </div>

                    <ul v-else class="divide-y divide-slate-100 -mx-5">
                        <li
                            v-for="make in makes"
                            :key="make.id"
                            class="group flex items-center gap-3 px-5 py-3 transition hover:bg-slate-50"
                            :class="focusedMake?.id === make.id ? 'bg-teal-50/50' : ''"
                        >
                            <button
                                type="button"
                                class="flex-1 text-start"
                                @click="focusedMake = make"
                            >
                                <p class="text-sm font-semibold text-slate-950">{{ make.name }}</p>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    {{ t('device_catalog.makes.model_count', { count: make.models_count ?? 0 }) }}
                                    <span v-if="(make.devices_count ?? 0) > 0" class="text-slate-400">
                                        · {{ t('device_catalog.makes.device_count', { count: make.devices_count ?? 0 }) }}
                                    </span>
                                </p>
                            </button>

                            <span
                                v-if="!make.is_active"
                                class="rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-slate-600"
                            >
                                {{ t('device_catalog.status.inactive') }}
                            </span>

                            <div v-if="canManage" class="flex items-center gap-1 opacity-0 transition group-hover:opacity-100">
                                <button
                                    type="button"
                                    class="grid size-7 place-items-center rounded-lg text-slate-500 hover:bg-slate-100"
                                    :aria-label="t('device_catalog.actions.edit')"
                                    @click="openEditMake(make)"
                                >
                                    <Pencil class="size-3.5" />
                                </button>
                                <button
                                    type="button"
                                    class="grid size-7 place-items-center rounded-lg text-rose-600 hover:bg-rose-50"
                                    :aria-label="t('device_catalog.actions.delete')"
                                    @click="removeMake(make)"
                                >
                                    <Trash2 class="size-3.5" />
                                </button>
                                <button
                                    type="button"
                                    class="rounded-lg border border-slate-200 bg-white px-2 py-1 text-[10px] font-semibold text-slate-700 hover:bg-slate-50"
                                    @click="toggleMakeActive(make)"
                                >
                                    {{ make.is_active ? t('device_catalog.actions.deactivate') : t('device_catalog.actions.activate') }}
                                </button>
                            </div>

                            <ChevronRight v-if="focusedMake?.id === make.id" class="size-4 text-teal-600" />
                        </li>
                    </ul>
                </section>

                <!-- =========================================================
                     MODELS PANE (right, 3 columns wide on lg)
                     ======================================================== -->
                <section class="lg:col-span-3 space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                                {{ t('device_catalog.models.title') }}
                                <span v-if="focusedMake" class="ml-1 text-teal-700">— {{ focusedMake.name }}</span>
                            </h2>
                            <p class="mt-1 text-xs text-slate-500">{{ t('device_catalog.models.subtitle') }}</p>
                        </div>
                        <button
                            v-if="canManage && focusedMake"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-lg bg-slate-950 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800"
                            @click="openCreateModel"
                        >
                            <Plus class="size-3.5" />
                            {{ t('device_catalog.models.new') }}
                        </button>
                    </div>

                    <label v-if="focusedMake" class="flex items-center gap-2 text-xs text-slate-600">
                        <input v-model="showInactiveModels" type="checkbox" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                        {{ t('device_catalog.show_inactive') }}
                    </label>

                    <!-- Empty state: nothing focused yet -->
                    <div v-if="!focusedMake" class="flex flex-col items-center gap-2 rounded-lg border border-dashed border-slate-200 p-10 text-center text-sm text-slate-500">
                        <ChevronRight class="size-6 text-slate-300" />
                        <p>{{ t('device_catalog.models.pick_a_make') }}</p>
                    </div>

                    <template v-else>
                        <div v-if="modelsError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700">
                            {{ modelsError }}
                        </div>

                        <div v-if="modelsLoading" class="p-6 text-center text-xs text-slate-500">
                            {{ t('common.loading') }}
                        </div>

                        <div v-else-if="models.length === 0" class="flex flex-col items-center gap-2 rounded-lg border border-dashed border-slate-200 p-6 text-center text-xs text-slate-500">
                            <MonitorSmartphone class="size-6 text-slate-300" />
                            <p>{{ t('device_catalog.models.empty') }}</p>
                        </div>

                        <div v-else class="overflow-x-auto -mx-5">
                            <table class="min-w-full divide-y divide-slate-200">
                                <thead class="bg-slate-50">
                                    <tr>
                                        <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('device_catalog.models.table.name') }}</th>
                                        <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('device_catalog.models.table.code') }}</th>
                                        <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('device_catalog.models.table.devices') }}</th>
                                        <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('device_catalog.models.table.status') }}</th>
                                        <th v-if="canManage" class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('device_catalog.models.table.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    <tr v-for="model in models" :key="model.id" class="transition hover:bg-slate-50">
                                        <td class="px-5 py-3 text-sm font-semibold text-slate-950">{{ model.name }}</td>
                                        <td class="px-5 py-3 text-xs font-mono text-slate-500">{{ model.code ?? '—' }}</td>
                                        <td class="px-5 py-3 text-xs text-slate-700">{{ model.devices_count ?? 0 }}</td>
                                        <td class="px-5 py-3">
                                            <span
                                                class="rounded-full px-2 py-1 text-[10px] font-bold uppercase tracking-wider"
                                                :class="model.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'"
                                            >
                                                {{ model.is_active ? t('device_catalog.status.active') : t('device_catalog.status.inactive') }}
                                            </span>
                                        </td>
                                        <td v-if="canManage" class="px-5 py-3">
                                            <div class="flex items-center justify-end gap-1">
                                                <button
                                                    type="button"
                                                    class="grid size-7 place-items-center rounded-lg text-slate-500 hover:bg-slate-100"
                                                    :aria-label="t('device_catalog.actions.edit')"
                                                    @click="openEditModel(model)"
                                                >
                                                    <Pencil class="size-3.5" />
                                                </button>
                                                <button
                                                    type="button"
                                                    class="rounded-lg border border-slate-200 bg-white px-2 py-1 text-[10px] font-semibold text-slate-700 hover:bg-slate-50"
                                                    @click="toggleModelActive(model)"
                                                >
                                                    {{ model.is_active ? t('device_catalog.actions.deactivate') : t('device_catalog.actions.activate') }}
                                                </button>
                                                <button
                                                    type="button"
                                                    class="grid size-7 place-items-center rounded-lg text-rose-600 hover:bg-rose-50"
                                                    :aria-label="t('device_catalog.actions.delete')"
                                                    @click="removeModel(model)"
                                                >
                                                    <Trash2 class="size-3.5" />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </template>
                </section>
            </div>
        </section>

        <!-- ===================== MAKE MODAL ============================ -->
        <BaseModal
            v-if="makeModalOpen"
            :title="makeModalMode === 'create' ? t('device_catalog.makes.modal_create') : t('device_catalog.makes.modal_edit')"
            size="md"
            :loading="makeSubmitting"
            @close="makeModalOpen = false"
        >
            <div v-if="makeModalError" class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                {{ makeModalError }}
            </div>

            <form id="makeForm" class="space-y-4" @submit.prevent="submitMake">
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('device_catalog.fields.name') }} *</span>
                    <input v-model="makeForm.name" type="text" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    <p v-if="makeFieldErrors.name" class="mt-1 text-xs text-rose-600">{{ makeFieldErrors.name[0] }}</p>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('device_catalog.fields.display_order') }}</span>
                    <input v-model.number="makeForm.display_order" type="number" min="0" max="9999" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                </label>
                <label class="flex items-center gap-2">
                    <input v-model="makeForm.is_active" type="checkbox" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                    <span class="text-sm font-medium text-slate-700">{{ t('device_catalog.fields.is_active') }}</span>
                </label>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="makeModalOpen = false">
                        {{ t('device_catalog.form.cancel') }}
                    </button>
                    <button
                        type="submit"
                        form="makeForm"
                        :disabled="makeSubmitting"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-wait disabled:opacity-70"
                    >
                        {{ makeSubmitting ? t('device_catalog.form.submitting') : t('device_catalog.form.save') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- ===================== MODEL MODAL =========================== -->
        <BaseModal
            v-if="modelModalOpen"
            size="md"
            :loading="modelSubmitting"
            @close="modelModalOpen = false"
        >
            <template #header>
                <h2 class="text-lg font-semibold text-slate-950">
                    {{ modelModalMode === 'create' ? t('device_catalog.models.modal_create') : t('device_catalog.models.modal_edit') }}
                </h2>
                <p class="mt-1 text-xs text-slate-500">
                    {{ t('device_catalog.models.under_make', { make: focusedMake?.name ?? '' }) }}
                </p>
            </template>

            <div v-if="modelModalError" class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                {{ modelModalError }}
            </div>

            <form id="modelForm" class="space-y-4" @submit.prevent="submitModel">
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('device_catalog.fields.name') }} *</span>
                    <input v-model="modelForm.name" type="text" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    <p v-if="modelFieldErrors.name" class="mt-1 text-xs text-rose-600">{{ modelFieldErrors.name[0] }}</p>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('device_catalog.fields.code') }}</span>
                    <input v-model="modelForm.code" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm font-mono focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    <p class="mt-1 text-xs text-slate-500">{{ t('device_catalog.form.code_help') }}</p>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('device_catalog.fields.display_order') }}</span>
                    <input v-model.number="modelForm.display_order" type="number" min="0" max="9999" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                </label>
                <label class="flex items-center gap-2">
                    <input v-model="modelForm.is_active" type="checkbox" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                    <span class="text-sm font-medium text-slate-700">{{ t('device_catalog.fields.is_active') }}</span>
                </label>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="modelModalOpen = false">
                        {{ t('device_catalog.form.cancel') }}
                    </button>
                    <button
                        type="submit"
                        form="modelForm"
                        :disabled="modelSubmitting"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-wait disabled:opacity-70"
                    >
                        {{ modelSubmitting ? t('device_catalog.form.submitting') : t('device_catalog.form.save') }}
                    </button>
                </div>
            </template>
        </BaseModal>
    </AdminLayout>
</template>
