<script setup lang="ts">
/**
 * Settings → Business Activities — admin CRUD for the platform-wide
 * activity catalogue that the merchant onboarding wizard picks from.
 *
 * Layout:
 *   - Header with "+ New activity" button (only visible with
 *     BusinessActivitiesManage permission).
 *   - Search + category filter + "Show inactive" toggle.
 *   - Table of activities. Inline buttons per row: Edit, Toggle
 *     active, Delete.
 *   - Modal for both Create and Edit (same fields).
 *
 * Behaviour notes:
 *   - Deleting an activity that is still attached to one or more
 *     merchants returns 409 with a human-readable message. The page
 *     surfaces that as a toast-style banner.
 *   - "Toggle active" is a one-click PATCH — useful for retiring
 *     activities without nuking historical merchant attachments.
 */

import { ClipboardList, Pencil, Plus, Search, Trash2, X } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { ApiError } from '@/lib/api';
import {
    createBusinessActivity,
    deleteBusinessActivity,
    listAllBusinessActivities,
    updateBusinessActivity,
    type BusinessActivityCategory,
    type BusinessActivityPayload,
} from '@/lib/api/businessActivities';
import { usePermissions } from '@/composables/usePermissions';
import { PlatformPermission } from '@/lib/permissions';
import type { BusinessActivity } from '@/lib/api/merchants';

const { t } = useI18n();
const { can } = usePermissions();

// --- Page state ----------------------------------------------------
const activities = ref<BusinessActivity[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
// Toast-style message for last action result (success or 409 delete
// block). Clears on next action.
const flash = ref<{ type: 'success' | 'error'; text: string } | null>(null);

// Filters.
const search = ref('');
const category = ref<BusinessActivityCategory | ''>('');
const showInactive = ref(false);

const categoryOptions: BusinessActivityCategory[] = [
    'food_and_beverage',
    'retail',
    'services',
    'hospitality',
    'healthcare',
    'education',
    'other',
];

// --- Modal state (shared by Create + Edit) -------------------------
const modalOpen = ref(false);
const modalMode = ref<'create' | 'edit'>('create');
// When editing, holds the id we're updating. Null when creating.
const editingId = ref<number | null>(null);
const submitting = ref(false);
const fieldErrors = ref<Record<string, string[]>>({});
const modalError = ref<string | null>(null);

// The form bound to the modal. Reactive so v-model picks up changes
// without `.value` plumbing.
const form = reactive<BusinessActivityPayload>({
    code: '',
    name_en: '',
    name_ar: '',
    category: 'food_and_beverage',
    isic_code: '',
    description_en: '',
    description_ar: '',
    is_active: true,
    display_order: 0,
});

function resetForm(): void {
    form.code = '';
    form.name_en = '';
    form.name_ar = '';
    form.category = 'food_and_beverage';
    form.isic_code = '';
    form.description_en = '';
    form.description_ar = '';
    form.is_active = true;
    form.display_order = 0;
    fieldErrors.value = {};
    modalError.value = null;
}

function openCreate(): void {
    modalMode.value = 'create';
    editingId.value = null;
    resetForm();
    modalOpen.value = true;
}

function openEdit(activity: BusinessActivity): void {
    modalMode.value = 'edit';
    editingId.value = activity.id;
    form.code = activity.code;
    form.name_en = activity.name_en;
    form.name_ar = activity.name_ar;
    form.category = activity.category as BusinessActivityCategory;
    form.isic_code = activity.isic_code ?? '';
    form.description_en = activity.description_en ?? '';
    form.description_ar = activity.description_ar ?? '';
    form.is_active = activity.is_active;
    form.display_order = activity.display_order;
    fieldErrors.value = {};
    modalError.value = null;
    modalOpen.value = true;
}

function closeModal(): void {
    modalOpen.value = false;
}

// --- Data loading --------------------------------------------------
async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const response = await listAllBusinessActivities({
            search: search.value || undefined,
            category: category.value || undefined,
            include_inactive: showInactive.value || undefined,
        });
        activities.value = response.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load activities';
    } finally {
        loading.value = false;
    }
}

// Debounce filter changes so typing in search doesn't fire a request
// per keystroke.
let debounceTimer: number | null = null;
watch([search, category, showInactive], () => {
    if (debounceTimer) {
        window.clearTimeout(debounceTimer);
    }
    debounceTimer = window.setTimeout(() => void load(), 250);
});

onMounted(() => void load());

// --- Submit (Create or Edit) ---------------------------------------
async function submit(): Promise<void> {
    submitting.value = true;
    fieldErrors.value = {};
    modalError.value = null;

    // Normalise empty strings to null so the server's nullable
    // validators don't reject empty optional fields.
    const payload: BusinessActivityPayload = {
        code: form.code,
        name_en: form.name_en,
        name_ar: form.name_ar,
        category: form.category,
        isic_code: form.isic_code || null,
        description_en: form.description_en || null,
        description_ar: form.description_ar || null,
        is_active: form.is_active,
        display_order: form.display_order ?? 0,
    };

    try {
        if (modalMode.value === 'create') {
            await createBusinessActivity(payload);
            flash.value = { type: 'success', text: t('business_activities.flash.created') };
        } else if (editingId.value !== null) {
            await updateBusinessActivity(editingId.value, payload);
            flash.value = { type: 'success', text: t('business_activities.flash.updated') };
        }
        closeModal();
        await load();
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            fieldErrors.value = err.payload.errors;
            modalError.value = t('business_activities.form.validation_summary');
        } else {
            modalError.value = err instanceof Error ? err.message : 'Submission failed';
        }
    } finally {
        submitting.value = false;
    }
}

// --- Quick actions: toggle active / delete -------------------------
async function toggleActive(activity: BusinessActivity): Promise<void> {
    try {
        await updateBusinessActivity(activity.id, { is_active: !activity.is_active });
        flash.value = { type: 'success', text: t('business_activities.flash.toggled') };
        await load();
    } catch (err) {
        flash.value = { type: 'error', text: err instanceof Error ? err.message : 'Failed' };
    }
}

async function remove(activity: BusinessActivity): Promise<void> {
    if (!window.confirm(t('business_activities.confirm_delete'))) {
        return;
    }
    try {
        await deleteBusinessActivity(activity.id);
        flash.value = { type: 'success', text: t('business_activities.flash.deleted') };
        await load();
    } catch (err) {
        // 409 surfaces here when the activity is still attached to
        // a merchant — the API returns a clear message we can show
        // verbatim.
        if (err instanceof ApiError && err.status === 409 && err.payload?.message) {
            flash.value = { type: 'error', text: err.payload.message };
        } else {
            flash.value = { type: 'error', text: err instanceof Error ? err.message : 'Delete failed' };
        }
    }
}

// --- Display helpers -----------------------------------------------
function categoryLabel(value: string): string {
    return t(`business_activities.categories.${value}`);
}
const canManage = computed(() => can(PlatformPermission.BusinessActivitiesManage));
</script>

<template>
    <AdminLayout>
        <section class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                        {{ t('business_activities.section_label') }}
                    </p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">
                        {{ t('business_activities.list_title') }}
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                        {{ t('business_activities.list_subtitle') }}
                    </p>
                </div>

                <button
                    v-if="canManage"
                    type="button"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800"
                    @click="openCreate"
                >
                    <Plus class="size-4" />
                    {{ t('business_activities.create_button') }}
                </button>
            </div>

            <!-- Flash banner: green for success, red for error. -->
            <div
                v-if="flash"
                class="rounded-lg border px-4 py-3 text-sm font-semibold"
                :class="flash.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-700'"
            >
                {{ flash.text }}
            </div>

            <!-- Filters -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_auto_auto]">
                <label class="flex min-w-0 items-center gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3 text-slate-500 shadow-sm">
                    <Search class="size-5 shrink-0" />
                    <input
                        v-model="search"
                        type="search"
                        class="w-full bg-transparent text-sm font-medium text-slate-950 outline-none placeholder:text-slate-400"
                        :placeholder="t('business_activities.search_placeholder')"
                    >
                </label>

                <select
                    v-model="category"
                    class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                >
                    <option value="">{{ t('business_activities.filter_all_categories') }}</option>
                    <option v-for="cat in categoryOptions" :key="cat" :value="cat">
                        {{ categoryLabel(cat) }}
                    </option>
                </select>

                <label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm">
                    <input v-model="showInactive" type="checkbox" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                    {{ t('business_activities.show_inactive') }}
                </label>
            </div>

            <div v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                {{ error }}
            </div>

            <!-- Table -->
            <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <div v-if="loading" class="p-10 text-center text-sm font-medium text-slate-500">
                    {{ t('common.loading') }}
                </div>

                <div v-else-if="activities.length === 0" class="flex flex-col items-center gap-3 p-12 text-center text-slate-500">
                    <ClipboardList class="size-10 text-slate-300" />
                    <p class="text-sm font-semibold">{{ t('business_activities.empty_state') }}</p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('business_activities.table.code') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('business_activities.table.name') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('business_activities.table.category') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('business_activities.table.isic') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('business_activities.table.order') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('business_activities.table.status') }}</th>
                                <th v-if="canManage" class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('business_activities.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="activity in activities" :key="activity.id" class="transition hover:bg-slate-50">
                                <td class="px-5 py-4 text-sm font-mono font-semibold text-slate-700">{{ activity.code }}</td>
                                <td class="px-5 py-4">
                                    <p class="text-sm font-semibold text-slate-950">{{ activity.name_en }}</p>
                                    <p dir="rtl" class="text-xs text-slate-500">{{ activity.name_ar }}</p>
                                </td>
                                <td class="px-5 py-4 text-sm font-medium text-slate-700">{{ categoryLabel(activity.category) }}</td>
                                <td class="px-5 py-4 text-xs font-mono text-slate-500">{{ activity.isic_code ?? '—' }}</td>
                                <td class="px-5 py-4 text-sm text-slate-700">{{ activity.display_order }}</td>
                                <td class="px-5 py-4">
                                    <span
                                        class="rounded-full px-3 py-1 text-xs font-bold uppercase tracking-wider"
                                        :class="activity.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'"
                                    >
                                        {{ activity.is_active ? t('business_activities.status.active') : t('business_activities.status.inactive') }}
                                    </span>
                                </td>
                                <td v-if="canManage" class="px-5 py-4">
                                    <div class="flex items-center justify-end gap-2">
                                        <button
                                            type="button"
                                            class="grid size-8 place-items-center rounded-lg text-slate-500 hover:bg-slate-100"
                                            :aria-label="t('business_activities.actions.edit')"
                                            @click="openEdit(activity)"
                                        >
                                            <Pencil class="size-4" />
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded-lg border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                            @click="toggleActive(activity)"
                                        >
                                            {{ activity.is_active ? t('business_activities.actions.deactivate') : t('business_activities.actions.activate') }}
                                        </button>
                                        <button
                                            type="button"
                                            class="grid size-8 place-items-center rounded-lg text-rose-600 hover:bg-rose-50"
                                            :aria-label="t('business_activities.actions.delete')"
                                            @click="remove(activity)"
                                        >
                                            <Trash2 class="size-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </section>

        <!-- Create / Edit modal -->
        <div
            v-if="modalOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 backdrop-blur-sm px-4"
            @click.self="closeModal"
        >
            <div class="w-full max-w-2xl rounded-xl bg-white p-6 shadow-xl">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-950">
                        {{ modalMode === 'create' ? t('business_activities.form.title_create') : t('business_activities.form.title_edit') }}
                    </h2>
                    <button type="button" class="rounded-lg p-1 text-slate-500 hover:bg-slate-100" @click="closeModal">
                        <X class="size-5" />
                    </button>
                </div>

                <div v-if="modalError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                    {{ modalError }}
                </div>

                <form class="mt-6 space-y-4" @submit.prevent="submit">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('business_activities.fields.code') }} *</span>
                            <input v-model="form.code" type="text" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm font-mono focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p v-if="fieldErrors.code" class="mt-1 text-xs text-rose-600">{{ fieldErrors.code[0] }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('business_activities.fields.category') }} *</span>
                            <select v-model="form.category" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <option v-for="cat in categoryOptions" :key="cat" :value="cat">
                                    {{ categoryLabel(cat) }}
                                </option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('business_activities.fields.name_en') }} *</span>
                            <input v-model="form.name_en" type="text" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p v-if="fieldErrors.name_en" class="mt-1 text-xs text-rose-600">{{ fieldErrors.name_en[0] }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('business_activities.fields.name_ar') }} *</span>
                            <input v-model="form.name_ar" type="text" dir="rtl" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                            <p v-if="fieldErrors.name_ar" class="mt-1 text-xs text-rose-600">{{ fieldErrors.name_ar[0] }}</p>
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('business_activities.fields.isic') }}</span>
                            <input v-model="form.isic_code" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm font-mono focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        </label>
                        <label class="block">
                            <span class="text-sm font-medium text-slate-700">{{ t('business_activities.fields.order') }}</span>
                            <input v-model.number="form.display_order" type="number" min="0" max="9999" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        </label>
                        <label class="block sm:col-span-2">
                            <span class="text-sm font-medium text-slate-700">{{ t('business_activities.fields.description_en') }}</span>
                            <textarea v-model="form.description_en" rows="2" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" />
                        </label>
                        <label class="block sm:col-span-2">
                            <span class="text-sm font-medium text-slate-700">{{ t('business_activities.fields.description_ar') }}</span>
                            <textarea v-model="form.description_ar" rows="2" dir="rtl" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100" />
                        </label>
                        <label class="flex items-center gap-2 sm:col-span-2">
                            <input v-model="form.is_active" type="checkbox" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                            <span class="text-sm font-medium text-slate-700">{{ t('business_activities.fields.is_active') }}</span>
                        </label>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="closeModal">
                            {{ t('business_activities.form.cancel') }}
                        </button>
                        <button
                            type="submit"
                            :disabled="submitting"
                            class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800 disabled:cursor-wait disabled:opacity-70"
                        >
                            {{ submitting ? t('business_activities.form.submitting') : (modalMode === 'create' ? t('business_activities.form.submit_create') : t('business_activities.form.submit_update')) }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
