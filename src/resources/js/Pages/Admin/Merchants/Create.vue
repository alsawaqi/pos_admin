<script setup lang="ts">
import { ArrowLeft, ArrowRight, Check, Plus, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { ApiError } from '@/lib/api';
import {
    createMerchant,
    listBusinessActivities,
    updateMerchantCommissionProfile,
    type BusinessActivity,
    type CommissionPartyType,
    type CreateMerchantPayload,
    type OwnerPayload,
} from '@/lib/api/merchants';
// Locale-aware country catalogue used by the owner cards' nationality
// select. sortedCountries returns the full catalogue sorted A→Z by
// the display name in the active language.
import { sortedCountries } from '@/lib/countries';

interface ActivitySelection {
    business_activity_id: number;
    is_primary: boolean;
}

const { t, locale } = useI18n();
const router = useRouter();

/**
 * Countries for the nationality dropdown. Computed so it re-sorts
 * automatically when the user flips the UI language between EN and
 * AR — the order should always be alphabetical in the displayed
 * language so the picker isn't disorienting.
 */
const countries = computed(() => sortedCountries(locale.value));

const currentStep = ref(0);
const steps = [
    { key: 'business', titleKey: 'merchants.wizard.step_business' },
    { key: 'owner', titleKey: 'merchants.wizard.step_owner' },
    { key: 'activities', titleKey: 'merchants.wizard.step_activities' },
    { key: 'commission', titleKey: 'merchants.wizard.step_commission' },
    { key: 'review', titleKey: 'merchants.wizard.step_review' },
] as const;

/**
 * Factory for a new blank owner card. Reused by the initial state
 * and by the "Add owner" button. The first card is always primary
 * by default; new cards added later default to non-primary so the
 * wizard keeps "exactly one primary" by construction.
 */
function makeBlankOwner(isPrimary: boolean): OwnerPayload {
    return {
        full_name_en: '',
        full_name_ar: '',
        civil_id: '',
        nationality: 'OM',
        phone: '',
        email: '',
        is_primary: isPrimary,
        ownership_percentage: null,
    };
}

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
    // Owners — start with one primary card. Admin clicks "Add owner"
    // to push more onto the array.
    owners: [makeBlankOwner(true)],
    activities: [],
    default_currency: 'OMR',
    default_locale: 'en',
});

/**
 * Push a new blank owner card onto the array (non-primary by
 * default so the existing primary stays correct).
 */
function addOwner(): void {
    form.owners.push(makeBlankOwner(false));
}

/**
 * Remove an owner card by array index. We never let the array
 * shrink below 1 — the server requires ≥ 1 owner.
 *
 * If the removed card was the primary, the first remaining card
 * becomes primary so the form stays in a valid state.
 */
function removeOwner(index: number): void {
    if (form.owners.length <= 1) {
        return;
    }
    const removed = form.owners.splice(index, 1)[0];
    const first = form.owners[0];
    if (removed?.is_primary && first) {
        first.is_primary = true;
    }
    // Clear any stale validation errors keyed on the removed index.
    fieldErrors.value = Object.fromEntries(
        Object.entries(fieldErrors.value).filter(([k]) => !k.startsWith(`owners.${index}.`)),
    );
}

/**
 * Set exactly one owner as primary, flipping all others off. Drives
 * the radio buttons across the owner cards.
 */
function setPrimaryOwner(index: number): void {
    form.owners.forEach((owner, i) => {
        owner.is_primary = i === index;
    });
}

const selectedActivities = ref<ActivitySelection[]>([]);
const availableActivities = ref<BusinessActivity[]>([]);
const submitting = ref(false);
const error = ref<string | null>(null);
const fieldErrors = ref<Record<string, string>>({});

// ---- Commission step ----------------------------------------------------
// The platform's revenue split for this merchant. The admin types the
// non-merchant party lines; the merchant takes the residual (100 - sum).
// Persisted right after the merchant is created (the create endpoint only
// makes the company; commission rides a follow-up call to the tested
// commission-profile endpoint).
const COMMISSION_PARTY_TYPES: CommissionPartyType[] = ['platform', 'bank', 'other'];
const commissionActive = ref(true);
const commissionShares = ref<Array<{ party_type: CommissionPartyType; label: string; percent: number }>>([]);

function commissionPartyLabel(type: CommissionPartyType): string {
    return t(`merchants.commission.party_options.${type}`);
}

const commissionPartiesTotal = computed(
    () => Math.round(commissionShares.value.reduce((sum, s) => sum + (Number(s.percent) || 0), 0) * 100) / 100,
);
const commissionMerchantPercent = computed(() => Math.round((100 - commissionPartiesTotal.value) * 100) / 100);
const commissionOverLimit = computed(() => commissionPartiesTotal.value > 100);

function addCommissionLine(): void {
    commissionShares.value.push({ party_type: 'platform', label: commissionPartyLabel('platform'), percent: 0 });
}

function removeCommissionLine(index: number): void {
    commissionShares.value.splice(index, 1);
}

function onCommissionPartyTypeChange(
    share: { party_type: CommissionPartyType; label: string },
    previousType: CommissionPartyType,
): void {
    if (!share.label.trim() || share.label === commissionPartyLabel(previousType)) {
        share.label = commissionPartyLabel(share.party_type);
    }
}

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
        // At least one owner exists by construction (the array
        // starts with one and removeOwner refuses to drop below 1).
        // We still validate every card has a name + exactly one is
        // marked primary so the user sees errors before submit.
        let primaryCount = 0;
        form.owners.forEach((owner, idx) => {
            if (!owner.full_name_en.trim()) {
                fieldErrors.value[`owners.${idx}.full_name_en`] = t('merchants.errors.owner_name_required');
            }
            if (owner.is_primary) {
                primaryCount++;
            }
        });
        if (primaryCount !== 1) {
            fieldErrors.value.owners = t('merchants.errors.exactly_one_primary');
        }
    } else if (currentStep.value === 3 && commissionOverLimit.value) {
        fieldErrors.value.commission = t('merchants.commission.over_limit');
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

/**
 * Replace empty string values with null in-place, skipping any fields the
 * backend marks as required.
 */
function normaliseEmptyToNull(
    target: Record<string, unknown>,
    requiredFields: readonly string[],
): void {
    for (const key of Object.keys(target)) {
        if (requiredFields.includes(key)) {
            continue;
        }

        if (target[key] === '') {
            target[key] = null;
        }
    }
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
    // Required fields (compliance.cr_number, owner.full_name_en) are skipped
    // — their emptiness is caught by the wizard's own validators.
    payload.name_ar = payload.name_ar || null;
    payload.legal_name = payload.legal_name || null;
    payload.legal_name_ar = payload.legal_name_ar || null;

    normaliseEmptyToNull(payload.compliance, ['cr_number']);
    normaliseEmptyToNull(payload.contact, []);
    // Apply the same empty→null normalisation to each owner row so
    // optional fields don't fail the server's nullable validators.
    payload.owners = payload.owners.map((owner) => {
        const copy: Record<string, unknown> = { ...owner };
        normaliseEmptyToNull(copy, ['full_name_en', 'is_primary']);
        return copy as unknown as OwnerPayload;
    });

    try {
        const response = await createMerchant(payload);

        // Persist the commission profile (if any lines were entered) via the
        // dedicated endpoint. Its failure must not strand the just-created
        // merchant — the admin can finish it on the Commission tab.
        if (commissionShares.value.length > 0) {
            try {
                await updateMerchantCommissionProfile(response.data.uuid, {
                    is_active: commissionActive.value,
                    shares: commissionShares.value.map((s) => ({
                        party_type: s.party_type,
                        label: s.label.trim() || commissionPartyLabel(s.party_type),
                        percent: Number(s.percent) || 0,
                    })),
                });
            } catch {
                // Swallowed by design — see the comment above.
            }
        }

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

            <ol class="grid gap-3 sm:grid-cols-5">
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
                <section v-show="currentStep === 0" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
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

                <section v-show="currentStep === 1" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-950">{{ t('merchants.wizard.section_owners') }}</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ t('merchants.wizard.owners_help') }}</p>
                        </div>
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50"
                            @click="addOwner"
                        >
                            <Plus class="size-4" />
                            {{ t('merchants.wizard.add_owner') }}
                        </button>
                    </div>

                    <!-- Owners array — one card per row. The primary
                         radio is shared across cards (all use the same
                         `name` attribute so the browser enforces the
                         single-selection). -->
                    <div class="space-y-4">
                        <div
                            v-for="(owner, index) in form.owners"
                            :key="index"
                            class="rounded-lg border border-slate-200 bg-slate-50/40 p-4"
                            :class="{ 'border-teal-300 bg-teal-50/50': owner.is_primary }"
                        >
                            <!-- Header: "Owner #N" + primary radio + remove button. -->
                            <div class="flex items-center justify-between gap-3 border-b border-slate-200 pb-3">
                                <span class="text-sm font-semibold text-slate-700">
                                    {{ t('merchants.wizard.owner_index', { index: index + 1 }) }}
                                </span>
                                <div class="flex items-center gap-3">
                                    <label class="flex items-center gap-2 text-xs font-semibold text-slate-600">
                                        <input
                                            type="radio"
                                            name="primary_owner"
                                            :checked="owner.is_primary"
                                            @change="setPrimaryOwner(index)"
                                        >
                                        {{ t('merchants.wizard.owner_primary') }}
                                    </label>
                                    <button
                                        v-if="form.owners.length > 1"
                                        type="button"
                                        class="grid size-8 place-items-center rounded-lg text-rose-600 hover:bg-rose-50"
                                        :aria-label="t('merchants.wizard.remove_owner')"
                                        @click="removeOwner(index)"
                                    >
                                        <Trash2 class="size-4" />
                                    </button>
                                </div>
                            </div>

                            <!-- Owner fields. Errors are per-index so
                                 multiple invalid cards each get the
                                 right message. -->
                            <div class="mt-4 grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.owner_name') }} *</label>
                                    <input
                                        v-model="owner.full_name_en"
                                        type="text"
                                        class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:ring-4 focus:ring-teal-100"
                                        @input="clearError(`owners.${index}.full_name_en`)"
                                    >
                                    <p v-if="fieldError(`owners.${index}.full_name_en`)" class="mt-1 text-xs font-medium text-rose-700">{{ fieldError(`owners.${index}.full_name_en`) }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.owner_name_ar') }}</label>
                                    <input v-model="owner.full_name_ar" type="text" dir="rtl" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:ring-4 focus:ring-teal-100">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.civil_id') }}</label>
                                    <input v-model="owner.civil_id" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:ring-4 focus:ring-teal-100">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.nationality') }}</label>
                                    <!-- Native <select> with ~250
                                         countries. Browsers handle
                                         large native selects well —
                                         users can type the first
                                         letter of the country to
                                         jump (e.g. "S" → Saudi
                                         Arabia). Option labels show
                                         in the active UI locale; the
                                         persisted value is always
                                         the ISO-2 code. -->
                                    <select
                                        v-model="owner.nationality"
                                        class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:ring-4 focus:ring-teal-100"
                                    >
                                        <option value="">—</option>
                                        <option v-for="country in countries" :key="country.code" :value="country.code">
                                            {{ locale === 'ar' ? country.name_ar : country.name_en }}
                                        </option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.owner_phone') }}</label>
                                    <input v-model="owner.phone" type="tel" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:ring-4 focus:ring-teal-100">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.owner_email') }}</label>
                                    <input v-model="owner.email" type="email" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:ring-4 focus:ring-teal-100">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-slate-700">{{ t('merchants.fields.ownership_percentage') }}</label>
                                    <div class="relative mt-1">
                                        <input
                                            v-model.number="owner.ownership_percentage"
                                            type="number"
                                            min="0"
                                            max="100"
                                            step="0.01"
                                            class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 pe-8 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:ring-4 focus:ring-teal-100"
                                        >
                                        <span class="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-slate-400">%</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <p
                        v-if="fieldError('owners')"
                        class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700"
                    >
                        {{ fieldError('owners') }}
                    </p>

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

                <section v-show="currentStep === 2" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
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

                <section v-show="currentStep === 3" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-950">{{ t('merchants.commission.title') }}</h2>
                            <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-600">{{ t('merchants.commission.subtitle') }}</p>
                        </div>
                        <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                            <input
                                v-model="commissionActive"
                                type="checkbox"
                                class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500"
                            >
                            {{ t('merchants.commission.active_label') }}
                        </label>
                    </div>

                    <div v-if="commissionShares.length" class="space-y-2">
                        <div class="hidden grid-cols-[1fr_1.4fr_7rem_2.5rem] gap-3 px-1 text-xs font-semibold uppercase tracking-wide text-slate-400 sm:grid">
                            <span>{{ t('merchants.commission.party_type') }}</span>
                            <span>{{ t('merchants.commission.label') }}</span>
                            <span>{{ t('merchants.commission.percent') }}</span>
                            <span></span>
                        </div>
                        <div
                            v-for="(share, index) in commissionShares"
                            :key="index"
                            class="grid grid-cols-2 gap-3 sm:grid-cols-[1fr_1.4fr_7rem_2.5rem] sm:items-center"
                        >
                            <select
                                :value="share.party_type"
                                class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100"
                                @change="(e) => { const prev = share.party_type; share.party_type = (e.target as HTMLSelectElement).value as CommissionPartyType; onCommissionPartyTypeChange(share, prev); }"
                            >
                                <option v-for="type in COMMISSION_PARTY_TYPES" :key="type" :value="type">{{ commissionPartyLabel(type) }}</option>
                            </select>
                            <input
                                v-model="share.label"
                                type="text"
                                maxlength="120"
                                class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100"
                            >
                            <div class="relative">
                                <input
                                    v-model.number="share.percent"
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 pr-7 text-sm font-medium tabular-nums text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100"
                                >
                                <span class="pointer-events-none absolute inset-y-0 right-2.5 flex items-center text-sm text-slate-400">%</span>
                            </div>
                            <button
                                type="button"
                                class="flex size-10 items-center justify-center rounded-lg border border-slate-200 text-slate-400 transition hover:border-rose-300 hover:text-rose-600"
                                :title="t('merchants.commission.remove')"
                                @click="removeCommissionLine(index)"
                            >
                                <Trash2 class="size-4" />
                            </button>
                        </div>
                    </div>
                    <p v-else class="rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-500">{{ t('merchants.commission.empty') }}</p>

                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-slate-300 px-3 py-2 text-sm font-semibold text-slate-600 transition hover:border-teal-400 hover:text-teal-700"
                        @click="addCommissionLine"
                    >
                        <Plus class="size-4" />
                        {{ t('merchants.commission.add_line') }}
                    </button>

                    <dl class="grid gap-2 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm">
                        <div class="flex items-center justify-between">
                            <dt class="text-slate-500">{{ t('merchants.commission.parties_total') }}</dt>
                            <dd class="font-semibold tabular-nums" :class="commissionOverLimit ? 'text-rose-700' : 'text-slate-900'">
                                {{ commissionPartiesTotal.toFixed(2) }}%
                            </dd>
                        </div>
                        <div class="flex items-center justify-between border-t border-slate-200 pt-2">
                            <dt class="font-semibold text-teal-800">{{ t('merchants.commission.merchant_share') }}</dt>
                            <dd class="text-lg font-bold tabular-nums text-teal-700">{{ commissionMerchantPercent.toFixed(2) }}%</dd>
                        </div>
                    </dl>
                    <p v-if="commissionOverLimit || fieldError('commission')" class="text-sm font-medium text-rose-700">
                        {{ t('merchants.commission.over_limit') }}
                    </p>
                </section>

                <section v-show="currentStep === 4" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
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
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.wizard.section_owners') }}</p>
                            <p class="mt-1 text-base font-semibold text-slate-950">
                                {{ t('merchants.wizard.owners_summary', { count: form.owners.length }) }}
                            </p>
                            <!-- Primary owner highlighted at the top so
                                 the reviewer can verify the canonical
                                 person of record without expanding. -->
                            <ul class="mt-2 space-y-1 text-sm text-slate-600">
                                <li v-for="(owner, idx) in form.owners" :key="idx" class="flex items-center justify-between">
                                    <span>{{ owner.full_name_en || '—' }}</span>
                                    <span v-if="owner.is_primary" class="rounded-full bg-teal-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-teal-700">
                                        {{ t('merchants.wizard.owner_primary') }}
                                    </span>
                                </li>
                            </ul>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.wizard.section_activities') }}</p>
                            <p class="mt-1 text-base font-semibold text-slate-950">
                                {{ t('merchants.wizard.activities_selected', { count: selectedActivities.length }) }}
                            </p>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.tabs.commission') }}</p>
                            <p class="mt-1 text-base font-semibold text-slate-950">
                                {{ t('merchants.commission.merchant_share') }}: {{ commissionMerchantPercent.toFixed(2) }}%
                            </p>
                            <p v-if="commissionShares.length" class="text-sm text-slate-600">
                                {{ commissionShares.length }} · {{ commissionPartiesTotal.toFixed(2) }}%
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
                        <ArrowLeft class="size-4 rtl:rotate-180" />
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
                        <ArrowRight class="size-4 rtl:rotate-180" />
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
