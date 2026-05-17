<script setup lang="ts">
import { ArrowLeft, ArrowRight, Check, ChevronDown } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { ApiError } from '@/lib/api';
import {
    createMerchant,
    listBusinessActivities,
    type BusinessActivity,
    type CreateMerchantPayload,
} from '@/lib/api/merchants';

interface ActivitySelection {
    business_activity_id: number;
    is_primary: boolean;
}

const { t } = useI18n();
const router = useRouter();

const currentStep = ref(0);
const steps = [
    { key: 'business', titleKey: 'merchants.wizard.step_business' },
    { key: 'owner', titleKey: 'merchants.wizard.step_owner' },
    { key: 'activities', titleKey: 'merchants.wizard.step_activities' },
    { key: 'review', titleKey: 'merchants.wizard.step_review' },
] as const;

const form = reactive<CreateMerchantPayload>({
    name: '',
    name_ar: '',
    legal_name: '',
    legal_name_ar: '',
    compliance: {
        cr_number: '',
        cr_issue_date: '',
        cr_expiry_date: '',
        establishment_date: '',
        tax_number: '',
        vat_number: '',
        vat_registered_at: '',
        chamber_of_commerce_number: '',
        municipality_license_number: '',
    },
    contact: { name: '', phone: '', email: '' },
    owner: {
        full_name_en: '',
        full_name_ar: '',
        civil_id: '',
        nationality: 'OM',
        phone: '',
        email: '',
    },
    activities: [],
    default_currency: 'OMR',
    default_locale: 'en',
});

const selectedActivities = ref<ActivitySelection[]>([]);
const availableActivities = ref<BusinessActivity[]>([]);
const submitting = ref(false);
const error = ref<string | null>(null);
const fieldErrors = ref<Record<string, string>>({});

onMounted(async () => {
    try {
        const response = await listBusinessActivities();
        availableActivities.value = response.data;
    } catch {
        // surface only on submit attempt
    }
});

const stepHasError = computed(() => Object.keys(fieldErrors.value).length > 0);

function fieldError(path: string): string | null {
    return fieldErrors.value[path] ?? null;
}

function clearError(path: string): void {
    if (fieldErrors.value[path]) {
        delete fieldErrors.value[path];
    }
}

function validateStep(): boolean {
    fieldErrors.value = {};

    if (currentStep.value === 0) {
        if (!form.name.trim()) {
            fieldErrors.value.name = t('merchants.errors.name_required');
        }
        if (!form.compliance.cr_number.trim()) {
            fieldErrors.value['compliance.cr_number'] = t('merchants.errors.cr_required');
        }
    } else if (currentStep.value === 1) {
        if (!form.owner.full_name_en.trim()) {
            fieldErrors.value['owner.full_name_en'] = t('merchants.errors.owner_name_required');
        }
    }

    return !stepHasError.value;
}

function next(): void {
    if (!validateStep()) {
        return;
    }
    currentStep.value = Math.min(currentStep.value + 1, steps.length - 1);
}

function previous(): void {
    currentStep.value = Math.max(currentStep.value - 1, 0);
}

function toggleActivity(activity: BusinessActivity): void {
    const idx = selectedActivities.value.findIndex((a) => a.business_activity_id === activity.id);
    if (idx >= 0) {
        selectedActivities.value.splice(idx, 1);
    } else {
        selectedActivities.value.push({ business_activity_id: activity.id, is_primary: selectedActivities.value.length === 0 });
    }
}

function setPrimary(activityId: number): void {
    selectedActivities.value = selectedActivities.value.map((a) => ({
        ...a,
        is_primary: a.business_activity_id === activityId,
    }));
}

function isSelected(activity: BusinessActivity): boolean {
    return selectedActivities.value.some((a) => a.business_activity_id === activity.id);
}

function selectedActivityName(activityId: number): string {
    const activity = availableActivities.value.find((a) => a.id === activityId);
    return activity?.name_en ?? `#${activityId}`;
}

async function submit(): Promise<void> {
    if (!validateStep()) {
        return;
    }

    submitting.value = true;
    error.value = null;
    fieldErrors.value = {};

    const payload: CreateMerchantPayload = {
        ...form,
        activities: selectedActivities.value.length > 0 ? selectedActivities.value : undefined,
    };

    // Normalise empty strings to null so backend nullable validators pass.
    payload.name_ar = payload.name_ar || null;
    payload.legal_name = payload.legal_name || null;
    payload.legal_name_ar = payload.legal_name_ar || null;
    Object.keys(payload.compliance).forEach((k) => {
        const key = k as keyof typeof payload.compliance;
        if (payload.compliance[key] === '') {
            payload.compliance[key] = null;
        }
    });
    Object.keys(payload.contact).forEach((k) => {
        const key = k as keyof typeof payload.contact;
        if (payload.contact[key] === '') {
            payload.contact[key] = null;
        }
    });
    Object.keys(payload.owner).forEach((k) => {
        const key = k as keyof typeof payload.owner;
        if (payload.owner[key] === '') {
            (payload.owner[key] as unknown) = null;
        }
    });

    try {
        const response = await createMerchant(payload);
        await router.replace(`/admin/merchants/${response.data.uuid}`);
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            const messages: Record<string, string> = {};
            for (const [field, errors] of Object.entries(err.payload.errors)) {
                if (errors[0]) {
                    messages[field] = errors[0];
                }
            }
            fieldErrors.value = messages;
            error.value = t('merchants.errors.validation_summary');
            currentStep.value = 0;
        } else {
            error.value = err instanceof Error ? err.message : 'Submission failed';
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <AdminLayout>
        <section class="space-y-8">
            <header>
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                    {{ t('merchants.section_label') }}
                </p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">
                    {{ t('merchants.wizard.title') }}
                </h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                    {{ t('merchants.wizard.subtitle') }}
                </p>
            </header>

            <ol class="grid gap-3 sm:grid-cols-4">
                <li
                    v-for="(step, index) in steps"
                    :key="step.key"
                    class="rounded-lg border px-4 py-3 text-sm font-semibold transition"
                    :class="
                        index < currentStep
                            ? 'border-teal-200 bg-teal-50 text-teal-800'
                            : index === currentStep
                                ? 'border-slate-900 bg-slate-950 text-white shadow-lg shadow-slate-950/15'
                                : 'border-slate-200 bg-white text-slate-500'
                    "
                >
                    <span class="block text-[10px] font-bold uppercase tracking-wider opacity-70">
                        {{ t('merchants.wizard.step_indicator', { current: index + 1, total: steps.length }) }}
                    </span>
                    {{ t(step.titleKey) }}
                </li>
            </ol>

            <div
                v-if="error"
                class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700"
            >
                {{ error }}
            </div>

            <form class="space-y-6" @submit.prevent>
                <section v-show="currentStep === 0" class="space-y-6 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-slate-950">{{ t('merchants.wizard.section_company') }}</h2>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.name') }}</label>
                            <input
                                v-model="form.name"
                                type="text"
                                class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100"
                                @input="clearError('name')"
                            >
                            <p v-if="fieldError('name')" class="mt-1 text-xs font-medium text-rose-700">{{ fieldError('name') }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.name_ar') }}</label>
                            <input
                                v-model="form.name_ar"
                                type="text"
                                dir="rtl"
                                class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.legal_name') }}</label>
                            <input
                                v-model="form.legal_name"
                                type="text"
                                class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.legal_name_ar') }}</label>
                            <input
                                v-model="form.legal_name_ar"
                                type="text"
                                dir="rtl"
                                class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100"
                            >
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-3">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.cr_number') }} *</label>
                            <input
                                v-model="form.compliance.cr_number"
                                type="text"
                                class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100"
                                @input="clearError('compliance.cr_number')"
                            >
                            <p v-if="fieldError('compliance.cr_number')" class="mt-1 text-xs font-medium text-rose-700">{{ fieldError('compliance.cr_number') }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.cr_issue_date') }}</label>
                            <input v-model="form.compliance.cr_issue_date" type="date" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.cr_expiry_date') }}</label>
                            <input v-model="form.compliance.cr_expiry_date" type="date" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.establishment_date') }}</label>
                            <input v-model="form.compliance.establishment_date" type="date" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.vat_number') }}</label>
                            <input v-model="form.compliance.vat_number" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.vat_registered_at') }}</label>
                            <input v-model="form.compliance.vat_registered_at" type="date" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.tax_number') }}</label>
                            <input v-model="form.compliance.tax_number" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.chamber_number') }}</label>
                            <input v-model="form.compliance.chamber_of_commerce_number" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.municipality_number') }}</label>
                            <input v-model="form.compliance.municipality_license_number" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                    </div>
                </section>

                <section v-show="currentStep === 1" class="space-y-6 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-slate-950">{{ t('merchants.wizard.section_owner') }}</h2>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.owner_name') }} *</label>
                            <input
                                v-model="form.owner.full_name_en"
                                type="text"
                                class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100"
                                @input="clearError('owner.full_name_en')"
                            >
                            <p v-if="fieldError('owner.full_name_en')" class="mt-1 text-xs font-medium text-rose-700">{{ fieldError('owner.full_name_en') }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.owner_name_ar') }}</label>
                            <input v-model="form.owner.full_name_ar" type="text" dir="rtl" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.civil_id') }}</label>
                            <input v-model="form.owner.civil_id" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.nationality') }}</label>
                            <input v-model="form.owner.nationality" type="text" maxlength="2" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium uppercase text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.owner_phone') }}</label>
                            <input v-model="form.owner.phone" type="tel" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.owner_email') }}</label>
                            <input v-model="form.owner.email" type="email" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                    </div>

                    <hr class="border-slate-200">

                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                        {{ t('merchants.wizard.section_contact') }}
                    </h3>

                    <div class="grid gap-4 md:grid-cols-3">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.contact_name') }}</label>
                            <input v-model="form.contact.name" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.contact_phone') }}</label>
                            <input v-model="form.contact.phone" type="tel" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.contact_email') }}</label>
                            <input v-model="form.contact.email" type="email" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                    </div>
                </section>

                <section v-show="currentStep === 2" class="space-y-6 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-slate-950">{{ t('merchants.wizard.section_activities') }}</h2>
                        <span class="text-xs font-semibold text-slate-500">
                            {{ t('merchants.wizard.activities_selected', { count: selectedActivities.length }) }}
                        </span>
                    </div>

                    <p class="text-sm text-slate-600">{{ t('merchants.wizard.activities_help') }}</p>

                    <div v-if="availableActivities.length === 0" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800">
                        {{ t('merchants.wizard.activities_loading') }}
                    </div>

                    <div v-else class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <button
                            v-for="activity in availableActivities"
                            :key="activity.id"
                            type="button"
                            class="flex flex-col items-start gap-1 rounded-lg border px-3 py-3 text-start text-sm transition"
                            :class="
                                isSelected(activity)
                                    ? 'border-teal-500 bg-teal-50 text-teal-800 shadow-sm'
                                    : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50'
                            "
                            @click="toggleActivity(activity)"
                        >
                            <span class="text-xs font-bold uppercase tracking-wide opacity-60">{{ activity.code }}</span>
                            <span class="font-semibold">{{ activity.name_en }}</span>
                            <span dir="rtl" class="text-xs text-slate-500">{{ activity.name_ar }}</span>
                        </button>
                    </div>

                    <div v-if="selectedActivities.length > 0" class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                            {{ t('merchants.wizard.primary_help') }}
                        </p>
                        <ul class="space-y-2">
                            <li
                                v-for="entry in selectedActivities"
                                :key="entry.business_activity_id"
                                class="flex items-center justify-between rounded-lg bg-white px-3 py-2 text-sm"
                            >
                                <span class="font-semibold text-slate-800">{{ selectedActivityName(entry.business_activity_id) }}</span>
                                <label class="flex items-center gap-2 text-xs font-semibold text-slate-600">
                                    <input
                                        type="radio"
                                        name="primary_activity"
                                        :checked="entry.is_primary"
                                        @change="setPrimary(entry.business_activity_id)"
                                    >
                                    {{ t('merchants.wizard.primary_label') }}
                                </label>
                            </li>
                        </ul>
                    </div>
                </section>

                <section v-show="currentStep === 3" class="space-y-6 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-slate-950">{{ t('merchants.wizard.section_review') }}</h2>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.fields.name') }}</p>
                            <p class="mt-1 text-base font-semibold text-slate-950">{{ form.name || '—' }}</p>
                            <p v-if="form.name_ar" dir="rtl" class="text-sm text-slate-600">{{ form.name_ar }}</p>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.fields.cr_number') }}</p>
                            <p class="mt-1 text-base font-semibold text-slate-950">{{ form.compliance.cr_number || '—' }}</p>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.fields.owner_name') }}</p>
                            <p class="mt-1 text-base font-semibold text-slate-950">{{ form.owner.full_name_en || '—' }}</p>
                            <p v-if="form.owner.email" class="text-sm text-slate-600">{{ form.owner.email }}</p>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.wizard.section_activities') }}</p>
                            <p class="mt-1 text-base font-semibold text-slate-950">
                                {{ t('merchants.wizard.activities_selected', { count: selectedActivities.length }) }}
                            </p>
                        </div>
                    </div>
                </section>

                <div class="flex items-center justify-between gap-3">
                    <button
                        v-if="currentStep > 0"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
                        @click="previous"
                    >
                        <ArrowLeft class="size-4" />
                        {{ t('common.previous') }}
                    </button>
                    <span v-else />

                    <button
                        v-if="currentStep < steps.length - 1"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800"
                        @click="next"
                    >
                        {{ t('common.next') }}
                        <ArrowRight class="size-4" />
                    </button>

                    <button
                        v-else
                        type="button"
                        :disabled="submitting"
                        class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-teal-500/30 transition hover:-translate-y-0.5 hover:bg-teal-700 disabled:opacity-60"
                        @click="submit"
                    >
                        <Check class="size-4" />
                        {{ submitting ? t('merchants.wizard.submitting') : t('merchants.wizard.submit') }}
                    </button>
                </div>
            </form>
        </section>
    </AdminLayout>
</template>
