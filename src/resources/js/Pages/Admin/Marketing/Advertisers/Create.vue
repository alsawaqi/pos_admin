<script setup lang="ts">
/**
 * Marketing → Advertisers → Onboard. A multi-step wizard for creating an
 * advertiser + its marketing-portal login. Two paths, picked on step 1:
 *
 *  • Existing merchant — reuse the merchant's company record, just mint the
 *    login (links via company_id, is_merchant = true).
 *  • New advertising company — onboard a brand-new company the same way a
 *    merchant is onboarded (company info → owners/contact → business activity),
 *    minus the commission step, then mint the login. The company is stored
 *    advertiser-only so it never shows up in the Merchants list.
 *
 * Strings are inline English to match the rest of the admin-internal advertiser
 * section (Advertisers/Index.vue). Mirrors the merchant wizard's field shapes
 * and styling so the two onboarding flows feel identical.
 */

import { ArrowLeft, ArrowRight, Building2, Check, Megaphone, Plus, Search, Store, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { ApiError } from '@/lib/api';
import {
    createAdvertiser,
    createAdvertiserCompany,
    listMerchantCompanies,
    type CreateAdvertiserCompanyPayload,
} from '@/lib/api/marketingAdvertisers';
import { listBusinessActivities, type BusinessActivity, type MerchantListItem, type OwnerPayload } from '@/lib/api/merchants';
import { sortedCountries } from '@/lib/countries';
import { useRouter } from 'vue-router';

const router = useRouter();

// Country catalogue for the owner nationality select. The advertiser admin
// section is English-first, so labels + sort are English.
const countries = sortedCountries('en');

type Mode = 'merchant' | 'new';
const mode = ref<Mode>('merchant');

interface ActivitySelection {
    business_activity_id: number;
    is_primary: boolean;
}

// ---- Company form (new-company path) ----------------------------------
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

const company = reactive({
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
    owners: [makeBlankOwner(true)] as OwnerPayload[],
});

function addOwner(): void {
    company.owners.push(makeBlankOwner(false));
}

function removeOwner(index: number): void {
    if (company.owners.length <= 1) return;
    const removed = company.owners.splice(index, 1)[0];
    const first = company.owners[0];
    if (removed?.is_primary && first) first.is_primary = true;
    fieldErrors.value = Object.fromEntries(
        Object.entries(fieldErrors.value).filter(([k]) => !k.startsWith(`owners.${index}.`)),
    );
}

function setPrimaryOwner(index: number): void {
    company.owners.forEach((owner, i) => {
        owner.is_primary = i === index;
    });
}

// ---- Business activities (new-company path) ---------------------------
const availableActivities = ref<BusinessActivity[]>([]);
const selectedActivities = ref<ActivitySelection[]>([]);

function toggleActivity(activity: BusinessActivity): void {
    const idx = selectedActivities.value.findIndex((a) => a.business_activity_id === activity.id);
    if (idx >= 0) {
        selectedActivities.value.splice(idx, 1);
    } else {
        selectedActivities.value.push({ business_activity_id: activity.id, is_primary: selectedActivities.value.length === 0 });
    }
}

function setPrimaryActivity(activityId: number): void {
    selectedActivities.value = selectedActivities.value.map((a) => ({
        ...a,
        is_primary: a.business_activity_id === activityId,
    }));
}

function isActivitySelected(activity: BusinessActivity): boolean {
    return selectedActivities.value.some((a) => a.business_activity_id === activity.id);
}

function activityName(activityId: number): string {
    return availableActivities.value.find((a) => a.id === activityId)?.name_en ?? `#${activityId}`;
}

// The primary activity's category seeds the advertiser's competitor-exclusion
// category so targeting can warn on conflicts. The admin can still override it
// on the login step.
const primaryActivityCategory = computed(() => {
    const primary = selectedActivities.value.find((a) => a.is_primary) ?? selectedActivities.value[0];
    if (!primary) return '';
    return availableActivities.value.find((a) => a.id === primary.business_activity_id)?.category ?? '';
});

// ---- Existing-merchant path -------------------------------------------
const merchants = ref<MerchantListItem[]>([]);
const merchantsLoaded = ref(false);
const merchantSearch = ref('');
const selectedMerchantId = ref<number | null>(null);

const selectedMerchant = computed(() => merchants.value.find((m) => m.id === selectedMerchantId.value) ?? null);

const filteredMerchants = computed(() => {
    const term = merchantSearch.value.trim().toLowerCase();
    if (!term) return merchants.value;
    return merchants.value.filter(
        (m) =>
            m.name.toLowerCase().includes(term) ||
            (m.cr_number ?? '').toLowerCase().includes(term) ||
            (m.contact.email ?? '').toLowerCase().includes(term),
    );
});

async function ensureMerchants(): Promise<void> {
    if (merchantsLoaded.value) return;
    try {
        const res = await listMerchantCompanies();
        merchants.value = res.data;
        merchantsLoaded.value = true;
    } catch {
        // Non-fatal — the picker stays empty; the field validates server-side.
    }
}

watch(mode, (m) => {
    if (m === 'merchant') void ensureMerchants();
});

// ---- Login (both paths) -----------------------------------------------
const account = reactive({
    email: '',
    password: '',
    brand_name: '',
    contact_name: '',
    phone: '',
    category: '',
});

// Placeholder/fallback brand shown when the admin leaves it blank.
const brandFallback = computed(() => (mode.value === 'merchant' ? selectedMerchant.value?.name ?? '' : company.name));

// ---- Stepper ----------------------------------------------------------
const ALL_STEPS = [
    { key: 'type', title: 'Type' },
    { key: 'company', title: 'Company info', modes: ['new'] },
    { key: 'contact', title: 'Owner & contact', modes: ['new'] },
    { key: 'activity', title: 'Business activity', modes: ['new'] },
    { key: 'login', title: 'Portal login' },
    { key: 'review', title: 'Review' },
] as const;

const steps = computed(() => ALL_STEPS.filter((s) => !('modes' in s) || (s.modes as readonly string[]).includes(mode.value)));
const currentStep = ref(0);
const currentKey = computed(() => steps.value[currentStep.value]?.key ?? 'type');

// Switching mode can change the visible step set — clamp the index so we never
// land past the end.
watch(steps, (list) => {
    if (currentStep.value > list.length - 1) currentStep.value = list.length - 1;
});

const submitting = ref(false);
const error = ref<string | null>(null);
const fieldErrors = ref<Record<string, string>>({});

function fieldError(path: string): string | null {
    return fieldErrors.value[path] ?? null;
}

function clearError(path: string): void {
    if (fieldErrors.value[path]) delete fieldErrors.value[path];
}

function validateStep(): boolean {
    const errs: Record<string, string> = {};
    const key = currentKey.value;

    if (key === 'type') {
        if (mode.value === 'merchant' && !selectedMerchantId.value) {
            errs.company_id = 'Select the merchant to link.';
        }
    } else if (key === 'company') {
        if (!company.name.trim()) errs.name = 'Company name is required.';
        if (!company.compliance.cr_number.trim()) errs['compliance.cr_number'] = 'Commercial registration number is required.';
    } else if (key === 'contact') {
        let primary = 0;
        company.owners.forEach((owner, idx) => {
            if (!owner.full_name_en.trim()) errs[`owners.${idx}.full_name_en`] = 'Owner name is required.';
            if (owner.is_primary) primary++;
        });
        if (primary !== 1) errs.owners = 'Exactly one owner must be marked as primary.';
    } else if (key === 'login') {
        if (!account.email.trim()) errs['account.email'] = 'Login email is required.';
        if (!account.password || account.password.length < 8) errs['account.password'] = 'Password must be at least 8 characters.';
    }

    fieldErrors.value = errs;
    return Object.keys(errs).length === 0;
}

function next(): void {
    if (!validateStep()) return;
    currentStep.value = Math.min(currentStep.value + 1, steps.value.length - 1);
}

function previous(): void {
    currentStep.value = Math.max(currentStep.value - 1, 0);
}

const STEP_FOR_PREFIX: Array<{ test: (k: string) => boolean; step: string }> = [
    { test: (k) => k === 'company_id', step: 'type' },
    { test: (k) => k === 'name' || k.startsWith('name_') || k.startsWith('legal_name') || k.startsWith('compliance.'), step: 'company' },
    { test: (k) => k === 'owners' || k.startsWith('owners.') || k.startsWith('contact.'), step: 'contact' },
    { test: (k) => k.startsWith('activities'), step: 'activity' },
    { test: (k) => k.startsWith('account.'), step: 'login' },
];

function jumpToFirstError(): void {
    const keys = Object.keys(fieldErrors.value);
    for (const step of steps.value) {
        if (keys.some((k) => STEP_FOR_PREFIX.find((p) => p.test(k))?.step === step.key)) {
            currentStep.value = steps.value.findIndex((s) => s.key === step.key);
            return;
        }
    }
}

function normaliseEmptyToNull(target: Record<string, unknown>, required: readonly string[]): void {
    for (const k of Object.keys(target)) {
        if (required.includes(k)) continue;
        if (target[k] === '') target[k] = null;
    }
}

async function submit(): Promise<void> {
    if (!validateStep()) return;

    submitting.value = true;
    error.value = null;
    fieldErrors.value = {};

    try {
        if (mode.value === 'merchant') {
            const res = await createAdvertiser({
                name: account.contact_name.trim() || account.brand_name.trim() || brandFallback.value || account.email,
                brand_name: account.brand_name.trim() || brandFallback.value || account.email,
                email: account.email,
                password: account.password,
                phone: account.phone || null,
                is_merchant: true,
                company_id: selectedMerchantId.value,
                category: account.category || null,
            });
            await router.replace(`/admin/marketing/advertisers?created=${res.data.id}`);
            return;
        }

        // New advertising company.
        const compliance: Record<string, unknown> = { ...company.compliance };
        normaliseEmptyToNull(compliance, ['cr_number']);
        const contact: Record<string, unknown> = { ...company.contact };
        normaliseEmptyToNull(contact, []);
        const owners = company.owners.map((owner) => {
            const copy: Record<string, unknown> = { ...owner };
            normaliseEmptyToNull(copy, ['full_name_en', 'is_primary']);
            return copy as unknown as OwnerPayload;
        });

        const payload: CreateAdvertiserCompanyPayload = {
            name: company.name,
            name_ar: company.name_ar || null,
            legal_name: company.legal_name || null,
            legal_name_ar: company.legal_name_ar || null,
            compliance: compliance as CreateAdvertiserCompanyPayload['compliance'],
            contact: contact as CreateAdvertiserCompanyPayload['contact'],
            owners,
            activities: selectedActivities.value.length > 0 ? selectedActivities.value : undefined,
            account: {
                email: account.email,
                password: account.password,
                brand_name: account.brand_name.trim() || null,
                contact_name: account.contact_name.trim() || null,
                phone: account.phone || null,
                category: account.category.trim() || primaryActivityCategory.value || null,
            },
        };

        const res = await createAdvertiserCompany(payload);
        await router.replace(`/admin/marketing/advertisers?created=${res.data.id}`);
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            const messages: Record<string, string> = {};
            for (const [field, errors] of Object.entries(err.payload.errors)) {
                if (errors[0]) messages[field] = errors[0];
            }
            fieldErrors.value = messages;
            error.value = 'Please fix the highlighted fields.';
            jumpToFirstError();
        } else {
            error.value = err instanceof Error ? err.message : 'Submission failed';
        }
    } finally {
        submitting.value = false;
    }
}

onMounted(() => {
    void ensureMerchants();
    void (async () => {
        try {
            const res = await listBusinessActivities();
            availableActivities.value = res.data;
        } catch {
            // surfaced only if the user reaches the activity step empty
        }
    })();
});
</script>

<template>
    <AdminLayout>
        <section class="space-y-8">
            <header>
                <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">Marketing</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">Onboard advertiser</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                    Link an existing POS merchant or register a brand-new advertising company, then create its marketing-portal login.
                </p>
            </header>

            <ol class="flex flex-wrap gap-3">
                <li
                    v-for="(step, index) in steps"
                    :key="step.key"
                    class="min-w-[8rem] flex-1 rounded-lg border px-4 py-3 text-sm font-semibold transition"
                    :class="
                        index < currentStep
                            ? 'border-teal-200 bg-teal-50 text-teal-800'
                            : index === currentStep
                                ? 'border-slate-900 bg-slate-950 text-white shadow-lg shadow-slate-950/15'
                                : 'border-slate-200 bg-white text-slate-500'
                    "
                >
                    <span class="block text-[10px] font-bold uppercase tracking-wider opacity-70">Step {{ index + 1 }} / {{ steps.length }}</span>
                    {{ step.title }}
                </li>
            </ol>

            <div v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                {{ error }}
            </div>

            <form class="space-y-6" @submit.prevent>
                <!-- Step: Type -->
                <section v-show="currentKey === 'type'" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-slate-950">Who is this advertiser?</h2>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <button
                            type="button"
                            class="flex flex-col items-start gap-2 rounded-xl border-2 p-5 text-start transition"
                            :class="mode === 'merchant' ? 'border-teal-500 bg-teal-50 shadow-sm' : 'border-slate-200 bg-white hover:border-slate-300'"
                            @click="mode = 'merchant'"
                        >
                            <Store class="size-6" :class="mode === 'merchant' ? 'text-teal-600' : 'text-slate-400'" />
                            <span class="text-base font-semibold text-slate-950">Existing POS merchant</span>
                            <span class="text-sm text-slate-600">Reuse the merchant's company details. Just create their advertising login.</span>
                        </button>
                        <button
                            type="button"
                            class="flex flex-col items-start gap-2 rounded-xl border-2 p-5 text-start transition"
                            :class="mode === 'new' ? 'border-teal-500 bg-teal-50 shadow-sm' : 'border-slate-200 bg-white hover:border-slate-300'"
                            @click="mode = 'new'"
                        >
                            <Building2 class="size-6" :class="mode === 'new' ? 'text-teal-600' : 'text-slate-400'" />
                            <span class="text-base font-semibold text-slate-950">New advertising company</span>
                            <span class="text-sm text-slate-600">Register a new company (trade name, CR, owner, activity) — advertising only.</span>
                        </button>
                    </div>

                    <!-- Merchant picker -->
                    <div v-if="mode === 'merchant'" class="space-y-3">
                        <label class="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3 text-slate-500 shadow-sm">
                            <Search class="size-5 shrink-0" />
                            <input
                                v-model="merchantSearch"
                                type="search"
                                class="w-full bg-transparent text-sm font-medium text-slate-950 outline-none placeholder:text-slate-400"
                                placeholder="Search merchant by name, CR, or email"
                            >
                        </label>
                        <p v-if="fieldError('company_id')" class="text-xs font-medium text-rose-700">{{ fieldError('company_id') }}</p>

                        <div class="max-h-80 space-y-2 overflow-y-auto rounded-lg border border-slate-200 bg-slate-50/40 p-2">
                            <p v-if="!merchantsLoaded" class="p-4 text-center text-sm text-slate-500">Loading merchants…</p>
                            <p v-else-if="filteredMerchants.length === 0" class="p-4 text-center text-sm text-slate-500">No merchants found.</p>
                            <button
                                v-for="m in filteredMerchants"
                                :key="m.id"
                                type="button"
                                class="flex w-full items-center justify-between rounded-lg border px-4 py-3 text-start text-sm transition"
                                :class="selectedMerchantId === m.id ? 'border-teal-500 bg-teal-50 text-teal-900 shadow-sm' : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300'"
                                @click="selectedMerchantId = m.id"
                            >
                                <span>
                                    <span class="block font-semibold">{{ m.name }}</span>
                                    <span class="block text-xs text-slate-500">CR {{ m.cr_number ?? '—' }} · {{ m.contact.email ?? '—' }}</span>
                                </span>
                                <Check v-if="selectedMerchantId === m.id" class="size-5 text-teal-600" />
                            </button>
                        </div>
                    </div>
                </section>

                <!-- Step: Company info (new) -->
                <section v-show="currentKey === 'company'" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-slate-950">Company information</h2>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">Trade name *</label>
                            <input v-model="company.name" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100" @input="clearError('name')">
                            <p v-if="fieldError('name')" class="mt-1 text-xs font-medium text-rose-700">{{ fieldError('name') }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">Trade name (Arabic)</label>
                            <input v-model="company.name_ar" type="text" dir="rtl" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">Legal name</label>
                            <input v-model="company.legal_name" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">Legal name (Arabic)</label>
                            <input v-model="company.legal_name_ar" type="text" dir="rtl" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-3">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">CR number *</label>
                            <input v-model="company.compliance.cr_number" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100" @input="clearError('compliance.cr_number')">
                            <p v-if="fieldError('compliance.cr_number')" class="mt-1 text-xs font-medium text-rose-700">{{ fieldError('compliance.cr_number') }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">CR issue date</label>
                            <input v-model="company.compliance.cr_issue_date" type="date" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">CR expiry date</label>
                            <input v-model="company.compliance.cr_expiry_date" type="date" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">Establishment date</label>
                            <input v-model="company.compliance.establishment_date" type="date" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">VAT number</label>
                            <input v-model="company.compliance.vat_number" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">VAT registered on</label>
                            <input v-model="company.compliance.vat_registered_at" type="date" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">Tax number</label>
                            <input v-model="company.compliance.tax_number" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">Chamber of commerce no.</label>
                            <input v-model="company.compliance.chamber_of_commerce_number" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">Municipality license no.</label>
                            <input v-model="company.compliance.municipality_license_number" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                    </div>
                </section>

                <!-- Step: Owner & contact (new) -->
                <section v-show="currentKey === 'contact'" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-950">Owner & primary contact</h2>
                            <p class="mt-1 text-sm text-slate-500">At least one owner; mark exactly one as the primary person of record.</p>
                        </div>
                        <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50" @click="addOwner">
                            <Plus class="size-4" />
                            Add owner
                        </button>
                    </div>

                    <div class="space-y-4">
                        <div
                            v-for="(owner, index) in company.owners"
                            :key="index"
                            class="rounded-lg border border-slate-200 bg-slate-50/40 p-4"
                            :class="{ 'border-teal-300 bg-teal-50/50': owner.is_primary }"
                        >
                            <div class="flex items-center justify-between gap-3 border-b border-slate-200 pb-3">
                                <span class="text-sm font-semibold text-slate-700">Owner #{{ index + 1 }}</span>
                                <div class="flex items-center gap-3">
                                    <label class="flex items-center gap-2 text-xs font-semibold text-slate-600">
                                        <input type="radio" name="primary_owner" :checked="owner.is_primary" @change="setPrimaryOwner(index)">
                                        Primary
                                    </label>
                                    <button v-if="company.owners.length > 1" type="button" class="grid size-8 place-items-center rounded-lg text-rose-600 hover:bg-rose-50" aria-label="Remove owner" @click="removeOwner(index)">
                                        <Trash2 class="size-4" />
                                    </button>
                                </div>
                            </div>

                            <div class="mt-4 grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="block text-sm font-semibold text-slate-700">Full name (English) *</label>
                                    <input v-model="owner.full_name_en" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:ring-4 focus:ring-teal-100" @input="clearError(`owners.${index}.full_name_en`)">
                                    <p v-if="fieldError(`owners.${index}.full_name_en`)" class="mt-1 text-xs font-medium text-rose-700">{{ fieldError(`owners.${index}.full_name_en`) }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-slate-700">Full name (Arabic)</label>
                                    <input v-model="owner.full_name_ar" type="text" dir="rtl" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:ring-4 focus:ring-teal-100">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-slate-700">Civil ID</label>
                                    <input v-model="owner.civil_id" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:ring-4 focus:ring-teal-100">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-slate-700">Nationality</label>
                                    <select v-model="owner.nationality" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:ring-4 focus:ring-teal-100">
                                        <option value="">—</option>
                                        <option v-for="c in countries" :key="c.code" :value="c.code">{{ c.name_en }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-slate-700">Phone</label>
                                    <input v-model="owner.phone" type="tel" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:ring-4 focus:ring-teal-100">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-slate-700">Email</label>
                                    <input v-model="owner.email" type="email" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:ring-4 focus:ring-teal-100">
                                </div>
                            </div>
                        </div>
                    </div>

                    <p v-if="fieldError('owners')" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700">{{ fieldError('owners') }}</p>

                    <hr class="border-slate-200">

                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Company contact</h3>
                    <div class="grid gap-4 md:grid-cols-3">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">Contact name</label>
                            <input v-model="company.contact.name" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">Contact phone</label>
                            <input v-model="company.contact.phone" type="tel" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">Contact email</label>
                            <input v-model="company.contact.email" type="email" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                    </div>
                </section>

                <!-- Step: Business activity (new) -->
                <section v-show="currentKey === 'activity'" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-semibold text-slate-950">Business activity</h2>
                        <span class="text-xs font-semibold text-slate-500">{{ selectedActivities.length }} selected</span>
                    </div>
                    <p class="text-sm text-slate-600">Drives slider filtration and the competitor-conflict warning when this advertiser's content is targeted to devices.</p>

                    <div v-if="availableActivities.length === 0" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800">
                        No business activities available yet.
                    </div>

                    <div v-else class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <button
                            v-for="activity in availableActivities"
                            :key="activity.id"
                            type="button"
                            class="flex flex-col items-start gap-1 rounded-lg border px-3 py-3 text-start text-sm transition"
                            :class="isActivitySelected(activity) ? 'border-teal-500 bg-teal-50 text-teal-800 shadow-sm' : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50'"
                            @click="toggleActivity(activity)"
                        >
                            <span class="text-xs font-bold uppercase tracking-wide opacity-60">{{ activity.code }}</span>
                            <span class="font-semibold">{{ activity.name_en }}</span>
                            <span dir="rtl" class="text-xs text-slate-500">{{ activity.name_ar }}</span>
                        </button>
                    </div>

                    <div v-if="selectedActivities.length > 0" class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Primary activity</p>
                        <ul class="space-y-2">
                            <li v-for="entry in selectedActivities" :key="entry.business_activity_id" class="flex items-center justify-between rounded-lg bg-white px-3 py-2 text-sm">
                                <span class="font-semibold text-slate-800">{{ activityName(entry.business_activity_id) }}</span>
                                <label class="flex items-center gap-2 text-xs font-semibold text-slate-600">
                                    <input type="radio" name="primary_activity" :checked="entry.is_primary" @change="setPrimaryActivity(entry.business_activity_id)">
                                    Primary
                                </label>
                            </li>
                        </ul>
                    </div>
                </section>

                <!-- Step: Portal login (both) -->
                <section v-show="currentKey === 'login'" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-slate-950">Marketing portal login</h2>
                    <p class="text-sm text-slate-600">The advertiser uses these to log in and upload content. Hand the password over securely — it's shown once.</p>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">Login email *</label>
                            <input v-model="account.email" type="email" autocomplete="off" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100" @input="clearError('account.email')">
                            <p v-if="fieldError('account.email')" class="mt-1 text-xs font-medium text-rose-700">{{ fieldError('account.email') }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">Password *</label>
                            <input v-model="account.password" type="text" minlength="8" autocomplete="off" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-mono text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100" @input="clearError('account.password')">
                            <p v-if="fieldError('account.password')" class="mt-1 text-xs font-medium text-rose-700">{{ fieldError('account.password') }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">Brand name</label>
                            <input v-model="account.brand_name" type="text" :placeholder="brandFallback || 'Defaults to the company name'" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">Contact person</label>
                            <input v-model="account.contact_name" type="text" placeholder="Defaults to the primary owner" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">Phone</label>
                            <input v-model="account.phone" type="tel" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-700">Category</label>
                            <input v-model="account.category" type="text" :placeholder="primaryActivityCategory || 'e.g. coffee, fashion'" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100">
                            <p class="mt-1 text-xs text-slate-400">Used to flag competitor conflicts when targeting sliders.</p>
                        </div>
                    </div>
                </section>

                <!-- Step: Review (both) -->
                <section v-show="currentKey === 'review'" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-slate-950">Review & create</h2>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Type</p>
                            <p class="mt-1 text-base font-semibold text-slate-950">{{ mode === 'merchant' ? 'Existing POS merchant' : 'New advertising company' }}</p>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Company</p>
                            <p class="mt-1 text-base font-semibold text-slate-950">{{ mode === 'merchant' ? (selectedMerchant?.name ?? '—') : (company.name || '—') }}</p>
                            <p v-if="mode === 'new'" class="text-sm text-slate-600">CR {{ company.compliance.cr_number || '—' }}</p>
                            <p v-else class="text-sm text-slate-600">CR {{ selectedMerchant?.cr_number ?? '—' }}</p>
                        </div>
                        <div v-if="mode === 'new'" class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Owners</p>
                            <ul class="mt-1 space-y-1 text-sm text-slate-600">
                                <li v-for="(owner, idx) in company.owners" :key="idx" class="flex items-center justify-between">
                                    <span>{{ owner.full_name_en || '—' }}</span>
                                    <span v-if="owner.is_primary" class="rounded-full bg-teal-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-teal-700">Primary</span>
                                </li>
                            </ul>
                        </div>
                        <div v-if="mode === 'new'" class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Business activity</p>
                            <p class="mt-1 text-base font-semibold text-slate-950">{{ selectedActivities.length }} selected</p>
                        </div>
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Portal login</p>
                            <p class="mt-1 text-base font-semibold text-slate-950">{{ account.email || '—' }}</p>
                            <p class="text-sm text-slate-600">Brand: {{ account.brand_name || brandFallback || '—' }}</p>
                        </div>
                    </div>
                </section>

                <div class="flex items-center justify-between gap-3">
                    <button v-if="currentStep > 0" type="button" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50" @click="previous">
                        <ArrowLeft class="size-4" />
                        Previous
                    </button>
                    <span v-else />

                    <button v-if="currentStep < steps.length - 1" type="button" class="inline-flex items-center gap-2 rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800" @click="next">
                        Next
                        <ArrowRight class="size-4" />
                    </button>

                    <button v-else type="button" :disabled="submitting" class="inline-flex items-center gap-2 rounded-lg bg-teal-600 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-teal-500/30 transition hover:-translate-y-0.5 hover:bg-teal-700 disabled:opacity-60" @click="submit">
                        <Megaphone class="size-4" />
                        {{ submitting ? 'Creating…' : 'Create advertiser' }}
                    </button>
                </div>
            </form>
        </section>
    </AdminLayout>
</template>
