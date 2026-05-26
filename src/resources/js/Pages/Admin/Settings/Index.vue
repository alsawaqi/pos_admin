<script setup lang="ts">
/**
 * Platform Settings — tabbed editor for the pos_settings catalogue.
 *
 * Routed at /admin/settings. Sidebar nav (gated by SettingsManage)
 * already points here.
 *
 * Tabs are derived from the distinct `group_key` values in the
 * server response. Group ordering is fixed in `groupOrder` so the
 * tab strip is stable across reloads.
 *
 * Save UX: a single global "Save changes" button per tab. The
 * button stays disabled until the form is dirty; on click we send
 * ONLY the changed keys (not the whole tab) so the audit log gets
 * one event per real change.
 */

import { Save, Settings as SettingsIcon } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { ApiError } from '@/lib/api';
import { getSettings, updateSettings, type PlatformSetting } from '@/lib/api/settings';

const { t, locale } = useI18n();

const settings = ref<PlatformSetting[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const saveError = ref<string | null>(null);
const saving = ref(false);
const flashMessage = ref<string | null>(null);

// Editable mirror keyed by setting key. We initialise from the
// server payload and treat dirtiness as "values[key] !== original".
const values = reactive<Record<string, unknown>>({});
const originals = reactive<Record<string, unknown>>({});

const activeTab = ref<string>('general');

// Stable display order for the tabs. New groups should be added
// here AND in en/ar.json's settings.groups.* in the same change.
const groupOrder: string[] = [
    'general',
    'localization',
    'merchant_defaults',
    'notifications',
    'maintenance',
];

const groupedSettings = computed<Record<string, PlatformSetting[]>>(() => {
    const groups: Record<string, PlatformSetting[]> = {};
    for (const s of settings.value) {
        if (!groups[s.group_key]) {
            groups[s.group_key] = [];
        }
        groups[s.group_key].push(s);
    }
    // Server already sorts by display_order; we trust it.
    return groups;
});

const visibleTabs = computed(() => {
    return groupOrder.filter((g) => groupedSettings.value[g]?.length);
});

const activeTabSettings = computed<PlatformSetting[]>(() => {
    return groupedSettings.value[activeTab.value] ?? [];
});

// Per-tab dirty flag: enables the Save button. Uses JSON
// stringify because settings can be primitives or arrays
// (multiselect).
const activeTabDirty = computed<boolean>(() => {
    return activeTabSettings.value.some(
        (s) => JSON.stringify(values[s.key]) !== JSON.stringify(originals[s.key]),
    );
});

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const response = await getSettings();
        settings.value = response.data;
        // Hydrate the editable mirror + the original snapshot.
        for (const s of response.data) {
            values[s.key] = cloneValue(s.value);
            originals[s.key] = cloneValue(s.value);
        }
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load settings';
    } finally {
        loading.value = false;
    }
}

function cloneValue(v: unknown): unknown {
    if (Array.isArray(v)) {
        return [...v];
    }
    return v;
}

async function saveActiveTab(): Promise<void> {
    saving.value = true;
    saveError.value = null;
    flashMessage.value = null;

    const changes: Record<string, unknown> = {};
    for (const s of activeTabSettings.value) {
        if (JSON.stringify(values[s.key]) !== JSON.stringify(originals[s.key])) {
            changes[s.key] = values[s.key];
        }
    }
    if (Object.keys(changes).length === 0) {
        saving.value = false;
        return;
    }

    try {
        const response = await updateSettings(changes);
        settings.value = response.data;
        // Re-snapshot so the dirty flag clears.
        for (const s of response.data) {
            originals[s.key] = cloneValue(s.value);
            values[s.key] = cloneValue(s.value);
        }
        flashMessage.value = t('settings.flash.saved');
        window.setTimeout(() => { flashMessage.value = null; }, 3000);
    } catch (err) {
        if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            saveError.value = String((err.payload as { message?: unknown }).message ?? 'Save failed');
        } else {
            saveError.value = err instanceof Error ? err.message : 'Save failed';
        }
    } finally {
        saving.value = false;
    }
}

// Label resolvers — pick the right language for the current
// locale, falling back to the other when the AR translation is
// missing (some seeded help strings are EN-only).
function labelFor(s: PlatformSetting): string {
    if (locale.value === 'ar' && s.label_ar) {
        return s.label_ar;
    }
    return s.label_en;
}
function helpFor(s: PlatformSetting): string | null {
    if (locale.value === 'ar' && s.help_ar) {
        return s.help_ar;
    }
    return s.help_en;
}
function optionLabel(opt: { value: string | number; label_en: string; label_ar: string }): string {
    if (locale.value === 'ar' && opt.label_ar) {
        return opt.label_ar;
    }
    return opt.label_en;
}

// Multi-select helper — toggle a value in/out of the array.
function toggleMultiselect(key: string, value: string | number): void {
    const current = Array.isArray(values[key]) ? (values[key] as (string | number)[]) : [];
    const idx = current.indexOf(value);
    values[key] = idx >= 0
        ? current.filter((v) => v !== value)
        : [...current, value];
}
function isSelected(key: string, value: string | number): boolean {
    const current = Array.isArray(values[key]) ? (values[key] as (string | number)[]) : [];
    return current.includes(value);
}

// email_list helper — admin types a comma-separated list, we
// split on save.
function emailListString(key: string): string {
    const v = values[key];
    return Array.isArray(v) ? v.join(', ') : '';
}
function setEmailListFromString(key: string, raw: string): void {
    values[key] = raw
        .split(/[,\n;]/)
        .map((s) => s.trim())
        .filter((s) => s.length > 0);
}

onMounted(load);
</script>

<template>
    <AdminLayout>
        <section class="space-y-6">
            <header>
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                    {{ t('settings.section_label') }}
                </p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">
                    {{ t('settings.title') }}
                </h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                    {{ t('settings.subtitle') }}
                </p>
            </header>

            <div v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                {{ error }}
            </div>

            <div v-if="loading" class="grid gap-3">
                <div class="h-10 rounded-lg border border-slate-200 bg-white animate-pulse" />
                <div class="h-80 rounded-lg border border-slate-200 bg-white animate-pulse" />
            </div>

            <template v-else-if="settings.length > 0">
                <!-- Tab strip -->
                <nav class="flex gap-2 overflow-x-auto border-b border-slate-200">
                    <button
                        v-for="group in visibleTabs"
                        :key="group"
                        type="button"
                        class="border-b-2 px-4 py-3 text-sm font-semibold transition"
                        :class="activeTab === group ? 'border-teal-600 text-teal-700' : 'border-transparent text-slate-500 hover:text-slate-800'"
                        @click="activeTab = group; saveError = null; flashMessage = null"
                    >
                        {{ t(`settings.groups.${group}`) }}
                    </button>
                </nav>

                <!-- Active tab body -->
                <div class="space-y-4">
                    <div v-if="saveError" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                        {{ saveError }}
                    </div>
                    <div v-if="flashMessage" class="rounded-lg border border-teal-200 bg-teal-50 px-4 py-3 text-sm font-semibold text-teal-700">
                        {{ flashMessage }}
                    </div>

                    <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="space-y-6">
                            <div v-for="s in activeTabSettings" :key="s.key" class="space-y-1.5">
                                <label class="block text-sm font-medium text-slate-800">
                                    {{ labelFor(s) }}
                                </label>

                                <!-- String / select / boolean / etc.
                                     Each branch is a small block to
                                     keep the template scannable. -->
                                <template v-if="s.type === 'string'">
                                    <input
                                        v-model="values[s.key]"
                                        type="text"
                                        class="w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                                    >
                                </template>

                                <template v-else-if="s.type === 'integer'">
                                    <input
                                        v-model.number="values[s.key]"
                                        type="number"
                                        class="w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                                    >
                                </template>

                                <template v-else-if="s.type === 'textarea'">
                                    <textarea
                                        v-model="values[s.key]"
                                        rows="3"
                                        class="w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                                    />
                                </template>

                                <template v-else-if="s.type === 'datetime'">
                                    <input
                                        v-model="values[s.key]"
                                        type="datetime-local"
                                        class="w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                                    >
                                </template>

                                <template v-else-if="s.type === 'boolean'">
                                    <label class="inline-flex cursor-pointer items-center gap-2">
                                        <input
                                            v-model="values[s.key]"
                                            type="checkbox"
                                            class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                                        >
                                        <span class="text-sm text-slate-700">{{ t('settings.boolean_enabled') }}</span>
                                    </label>
                                </template>

                                <template v-else-if="s.type === 'select'">
                                    <select
                                        v-model="values[s.key]"
                                        class="w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                                    >
                                        <option
                                            v-for="opt in s.options ?? []"
                                            :key="opt.value"
                                            :value="opt.value"
                                        >
                                            {{ optionLabel(opt) }}
                                        </option>
                                    </select>
                                </template>

                                <template v-else-if="s.type === 'multiselect'">
                                    <div class="flex flex-wrap gap-2">
                                        <label
                                            v-for="opt in s.options ?? []"
                                            :key="opt.value"
                                            class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm transition"
                                            :class="isSelected(s.key, opt.value) ? 'border-teal-400 bg-teal-50 text-teal-800' : 'text-slate-700 hover:bg-slate-50'"
                                        >
                                            <input
                                                type="checkbox"
                                                :checked="isSelected(s.key, opt.value)"
                                                class="size-3.5 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                                                @change="toggleMultiselect(s.key, opt.value)"
                                            >
                                            {{ optionLabel(opt) }}
                                        </label>
                                    </div>
                                </template>

                                <template v-else-if="s.type === 'email_list'">
                                    <textarea
                                        :value="emailListString(s.key)"
                                        rows="2"
                                        :placeholder="t('settings.email_list_placeholder')"
                                        class="w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                                        @input="setEmailListFromString(s.key, ($event.target as HTMLTextAreaElement).value)"
                                    />
                                </template>

                                <p v-if="helpFor(s)" class="text-xs text-slate-500">{{ helpFor(s) }}</p>
                            </div>
                        </div>

                        <div class="mt-6 flex items-center justify-end gap-2 border-t border-slate-100 pt-4">
                            <button
                                type="button"
                                class="inline-flex items-center gap-2 rounded-lg bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="!activeTabDirty || saving"
                                @click="saveActiveTab"
                            >
                                <Save class="size-4" />
                                {{ saving ? t('settings.saving') : t('common.save') }}
                            </button>
                        </div>
                    </div>
                </div>
            </template>

            <div v-else class="flex flex-col items-center gap-3 rounded-lg border border-slate-200 bg-white p-12 text-center text-slate-500 shadow-sm">
                <SettingsIcon class="size-10 text-slate-300" />
                <p class="text-sm font-semibold">{{ t('settings.empty_state') }}</p>
            </div>
        </section>
    </AdminLayout>
</template>
