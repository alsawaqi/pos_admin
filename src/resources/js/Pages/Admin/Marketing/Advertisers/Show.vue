<script setup lang="ts">
/**
 * Marketing → Advertisers → detail. A tabbed view mirroring the merchant detail
 * page: Overview, Account, Company (editable for advertising-only companies),
 * Owners, Activities, Content (their uploads), plus Documents + Status (slider
 * delivery) placeholders for later. Reached by clicking an advertiser row.
 */

import {
    ArrowLeft, Building2, CheckCircle2, FileText, Image as ImageIcon,
    KeyRound, LayoutGrid, Megaphone, Plus, Tags, Trash2, Users, Video,
} from 'lucide-vue-next';
import { computed, onMounted, reactive, ref, watch, type Component } from 'vue';
import { useRoute } from 'vue-router';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import MediaLightbox from '@/Components/MediaLightbox.vue';
import { ApiError } from '@/lib/api';
import {
    getAdvertiser,
    resetAdvertiserPassword,
    syncAdvertiserActivities,
    updateAdvertiser,
    updateAdvertiserCompany,
    type AdvertiserDetail,
} from '@/lib/api/marketingAdvertisers';
import { listReviewContent, type ReviewContentItem } from '@/lib/api/marketingContent';
import { listBusinessActivities, type BusinessActivity, type OwnerPayload } from '@/lib/api/merchants';
import { sortedCountries } from '@/lib/countries';
import { usePermissions } from '@/composables/usePermissions';
import { PlatformPermission } from '@/lib/permissions';

const { can } = usePermissions();
const canManage = computed(() => can(PlatformPermission.MarketingAdvertisersManage));

const route = useRoute();
const id = Number(route.params.id);
const countries = sortedCountries('en');

const adv = ref<AdvertiserDetail | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const flash = ref<{ type: 'success' | 'error'; text: string } | null>(null);
const revealed = ref<string | null>(null);

type Tab = 'overview' | 'account' | 'company' | 'owners' | 'activities' | 'content' | 'documents' | 'status';
const tab = ref<Tab>('overview');

const company = computed(() => adv.value?.company ?? null);
const editableCompany = computed(() => company.value?.is_advertiser_only === true);

const tabs = computed(() => {
    const base: Array<{ key: Tab; label: string; icon: Component }> = [
        { key: 'overview', label: 'Overview', icon: LayoutGrid },
        { key: 'account', label: 'Account', icon: KeyRound },
    ];
    if (company.value) {
        base.push(
            { key: 'company', label: 'Company', icon: Building2 },
            { key: 'owners', label: 'Owners', icon: Users },
            { key: 'activities', label: 'Activities', icon: Tags },
        );
    }
    base.push(
        { key: 'content', label: 'Content', icon: ImageIcon },
        { key: 'documents', label: 'Documents', icon: FileText },
        { key: 'status', label: 'Status', icon: Megaphone },
    );
    return base;
});

// ---- Load ----------------------------------------------------------------
async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const res = await getAdvertiser(id);
        adv.value = res.data;
        initForms();
    } catch (err) {
        error.value = messageOf(err);
    } finally {
        loading.value = false;
    }
}

function initForms(): void {
    const a = adv.value;
    if (!a) return;
    account.name = a.name;
    account.brand_name = a.brand_name;
    account.phone = a.phone ?? '';
    account.category = a.category ?? '';

    const c = a.company;
    if (c) {
        comp.name = c.name;
        comp.name_ar = c.name_ar ?? '';
        comp.legal_name = c.legal_name ?? '';
        comp.legal_name_ar = c.legal_name_ar ?? '';
        comp.compliance = { ...emptyCompliance, ...nullsToEmpty(c.compliance) };
        comp.contact = { name: c.contact.name ?? '', phone: c.contact.phone ?? '', email: c.contact.email ?? '' };
        owners.value = c.owners.length
            ? c.owners.map((o) => ({ ...o, full_name_ar: o.full_name_ar ?? '', civil_id: o.civil_id ?? '', nationality: o.nationality ?? 'OM', phone: o.phone ?? '', email: o.email ?? '' }))
            : [blankOwner(true)];
        selectedActivities.value = c.activities.map((x) => ({ business_activity_id: x.id, is_primary: !!x.is_primary }));
    }
}

function nullsToEmpty(o: Record<string, string | null>): Record<string, string> {
    return Object.fromEntries(Object.entries(o).map(([k, v]) => [k, v ?? '']));
}

// ---- Account tab ---------------------------------------------------------
const account = reactive({ name: '', brand_name: '', phone: '', category: '' });
const savingAccount = ref(false);

async function saveAccount(): Promise<void> {
    savingAccount.value = true;
    flash.value = null;
    try {
        await updateAdvertiser(id, {
            name: account.name,
            brand_name: account.brand_name,
            phone: account.phone || null,
            category: account.category || null,
        });
        flash.value = { type: 'success', text: 'Account updated.' };
        await load();
    } catch (err) {
        flash.value = { type: 'error', text: messageOf(err) };
    } finally {
        savingAccount.value = false;
    }
}

async function toggleStatus(): Promise<void> {
    if (!adv.value) return;
    const next = adv.value.status === 'active' ? 'suspended' : 'active';
    try {
        await updateAdvertiser(id, { status: next });
        flash.value = { type: 'success', text: next === 'suspended' ? 'Advertiser suspended.' : 'Advertiser reactivated.' };
        await load();
    } catch (err) {
        flash.value = { type: 'error', text: messageOf(err) };
    }
}

async function resetPw(): Promise<void> {
    if (!window.confirm('Reset this advertiser’s password? They’ll need the new one to log in.')) return;
    try {
        const res = await resetAdvertiserPassword(id);
        revealed.value = res.data.password;
        flash.value = { type: 'success', text: 'Password reset.' };
    } catch (err) {
        flash.value = { type: 'error', text: messageOf(err) };
    }
}

// ---- Company tab ---------------------------------------------------------
const emptyCompliance = {
    cr_number: '', cr_issue_date: '', cr_expiry_date: '', establishment_date: '',
    tax_number: '', vat_number: '', vat_registered_at: '', chamber_of_commerce_number: '', municipality_license_number: '',
};
const comp = reactive({
    name: '', name_ar: '', legal_name: '', legal_name_ar: '',
    compliance: { ...emptyCompliance } as Record<string, string>,
    contact: { name: '', phone: '', email: '' },
});
const savingCompany = ref(false);
const fieldErrors = ref<Record<string, string[]>>({});

async function saveCompany(): Promise<void> {
    savingCompany.value = true;
    flash.value = null;
    fieldErrors.value = {};
    try {
        const res = await updateAdvertiserCompany(id, {
            name: comp.name,
            name_ar: comp.name_ar || null,
            legal_name: comp.legal_name || null,
            legal_name_ar: comp.legal_name_ar || null,
            compliance: {
                cr_number: comp.compliance.cr_number ?? '',
                cr_issue_date: comp.compliance.cr_issue_date || null,
                cr_expiry_date: comp.compliance.cr_expiry_date || null,
                establishment_date: comp.compliance.establishment_date || null,
                tax_number: comp.compliance.tax_number || null,
                vat_number: comp.compliance.vat_number || null,
                vat_registered_at: comp.compliance.vat_registered_at || null,
                chamber_of_commerce_number: comp.compliance.chamber_of_commerce_number || null,
                municipality_license_number: comp.compliance.municipality_license_number || null,
            },
            contact: { name: comp.contact.name || null, phone: comp.contact.phone || null, email: comp.contact.email || null },
        });
        adv.value = res.data;
        initForms();
        flash.value = { type: 'success', text: 'Company details saved.' };
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            fieldErrors.value = err.payload.errors;
            flash.value = { type: 'error', text: 'Please fix the highlighted fields.' };
        } else {
            flash.value = { type: 'error', text: messageOf(err) };
        }
    } finally {
        savingCompany.value = false;
    }
}

// ---- Owners tab ----------------------------------------------------------
function blankOwner(isPrimary: boolean): OwnerPayload {
    return { full_name_en: '', full_name_ar: '', civil_id: '', nationality: 'OM', phone: '', email: '', is_primary: isPrimary, ownership_percentage: null };
}
const owners = ref<OwnerPayload[]>([blankOwner(true)]);
const savingOwners = ref(false);

function addOwner(): void { owners.value.push(blankOwner(false)); }
function removeOwner(i: number): void {
    if (owners.value.length <= 1) return;
    const removed = owners.value.splice(i, 1)[0];
    if (removed?.is_primary && owners.value[0]) owners.value[0].is_primary = true;
}
function setPrimaryOwner(i: number): void { owners.value.forEach((o, idx) => { o.is_primary = idx === i; }); }

async function saveOwners(): Promise<void> {
    savingOwners.value = true;
    flash.value = null;
    fieldErrors.value = {};
    try {
        const res = await updateAdvertiserCompany(id, {
            owners: owners.value.map((o) => ({
                ...o,
                full_name_ar: o.full_name_ar || null,
                civil_id: o.civil_id || null,
                nationality: o.nationality || null,
                phone: o.phone || null,
                email: o.email || null,
            })),
        });
        adv.value = res.data;
        initForms();
        flash.value = { type: 'success', text: 'Owners saved.' };
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            fieldErrors.value = err.payload.errors;
            flash.value = { type: 'error', text: 'Please fix the owner details.' };
        } else {
            flash.value = { type: 'error', text: messageOf(err) };
        }
    } finally {
        savingOwners.value = false;
    }
}

// ---- Activities tab ------------------------------------------------------
const availableActivities = ref<BusinessActivity[]>([]);
const selectedActivities = ref<Array<{ business_activity_id: number; is_primary: boolean }>>([]);
const savingActivities = ref(false);

function isActivity(a: BusinessActivity): boolean { return selectedActivities.value.some((x) => x.business_activity_id === a.id); }
function toggleActivity(a: BusinessActivity): void {
    const i = selectedActivities.value.findIndex((x) => x.business_activity_id === a.id);
    if (i >= 0) selectedActivities.value.splice(i, 1);
    else selectedActivities.value.push({ business_activity_id: a.id, is_primary: selectedActivities.value.length === 0 });
}
function setPrimaryActivity(activityId: number): void {
    selectedActivities.value = selectedActivities.value.map((x) => ({ ...x, is_primary: x.business_activity_id === activityId }));
}
function activityName(activityId: number): string {
    return availableActivities.value.find((a) => a.id === activityId)?.name_en ?? `#${activityId}`;
}

async function saveActivities(): Promise<void> {
    savingActivities.value = true;
    flash.value = null;
    try {
        const res = await syncAdvertiserActivities(id, selectedActivities.value);
        adv.value = res.data;
        initForms();
        flash.value = { type: 'success', text: 'Activities saved.' };
    } catch (err) {
        flash.value = { type: 'error', text: messageOf(err) };
    } finally {
        savingActivities.value = false;
    }
}

// ---- Content tab ---------------------------------------------------------
const content = ref<ReviewContentItem[]>([]);
const contentTab = ref<'pending' | 'reviewed'>('pending');
const contentLoading = ref(false);
let contentLoaded = false;
const lightbox = ref<ReviewContentItem | null>(null);

const statusClass: Record<string, string> = {
    pending: 'bg-amber-100 text-amber-700', approved: 'bg-emerald-100 text-emerald-700',
    live: 'bg-teal-100 text-teal-700', expired: 'bg-slate-200 text-slate-600',
    rejected: 'bg-rose-100 text-rose-700', draft: 'bg-slate-200 text-slate-600',
};

async function loadContent(): Promise<void> {
    contentLoading.value = true;
    try {
        const res = await listReviewContent({ view: contentTab.value, advertiser_id: id });
        content.value = res.data;
        contentLoaded = true;
    } catch (err) {
        flash.value = { type: 'error', text: messageOf(err) };
    } finally {
        contentLoading.value = false;
    }
}

watch(tab, (t) => {
    if (t === 'content' && !contentLoaded) void loadContent();
    if (t === 'activities' && availableActivities.value.length === 0) void loadActivities();
});
watch(contentTab, () => void loadContent());

async function loadActivities(): Promise<void> {
    try {
        const res = await listBusinessActivities();
        availableActivities.value = res.data;
    } catch {
        // non-fatal
    }
}

function messageOf(err: unknown): string {
    if (err instanceof ApiError) {
        const msg = (err.payload as { message?: unknown } | null)?.message;
        if (typeof msg === 'string' && msg) return msg;
    }
    return err instanceof Error ? err.message : 'Something went wrong';
}

function fieldError(path: string): string | null {
    return fieldErrors.value[path]?.[0] ?? null;
}

onMounted(() => void load());
</script>

<template>
    <AdminLayout>
        <section class="space-y-6">
            <div>
                <RouterLink to="/admin/marketing/advertisers" class="inline-flex items-center gap-1.5 text-sm font-semibold text-teal-700 hover:text-teal-800">
                    <ArrowLeft class="size-4" /> Advertisers
                </RouterLink>
                <div v-if="adv" class="mt-2 flex flex-wrap items-center gap-3">
                    <h1 class="text-3xl font-semibold tracking-tight text-slate-950">{{ adv.brand_name }}</h1>
                    <span class="rounded-full px-3 py-1 text-xs font-bold uppercase tracking-wider" :class="adv.status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'">{{ adv.status }}</span>
                    <span v-if="adv.is_merchant" class="rounded-full bg-teal-50 px-3 py-1 text-xs font-semibold text-teal-700">Merchant</span>
                    <span v-else-if="adv.company" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-500">Advertising company</span>
                </div>
            </div>

            <div v-if="revealed" class="rounded-lg border border-teal-200 bg-teal-50 px-4 py-3 text-sm text-teal-900">
                <span class="font-semibold">New password:</span>
                <code class="ml-2 rounded bg-white px-2 py-1 font-mono text-teal-800 ring-1 ring-teal-200">{{ revealed }}</code>
                <button type="button" class="ml-3 text-xs font-semibold text-teal-700 hover:underline" @click="revealed = null">Dismiss</button>
            </div>

            <div v-if="flash" class="rounded-lg border px-4 py-3 text-sm font-semibold" :class="flash.type === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-700'">{{ flash.text }}</div>

            <div v-if="loading" class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500 shadow-sm">Loading…</div>
            <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">{{ error }}</div>

            <template v-else-if="adv">
                <!-- Tabs -->
                <div class="flex flex-wrap gap-2 border-b border-slate-200">
                    <button
                        v-for="t in tabs"
                        :key="t.key"
                        type="button"
                        class="-mb-px inline-flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-semibold transition"
                        :class="tab === t.key ? 'border-teal-500 text-teal-700' : 'border-transparent text-slate-500 hover:text-slate-800'"
                        @click="tab = t.key"
                    >
                        <component :is="t.icon" class="size-4" />
                        {{ t.label }}
                    </button>
                </div>

                <!-- OVERVIEW -->
                <div v-show="tab === 'overview'" class="grid gap-5 lg:grid-cols-2">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Advertiser</h2>
                        <dl class="mt-3 space-y-2 text-sm">
                            <div class="flex justify-between gap-4"><dt class="text-slate-500">Contact</dt><dd class="font-medium text-slate-900">{{ adv.name }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-slate-500">Email</dt><dd class="font-medium text-slate-900">{{ adv.email }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-slate-500">Phone</dt><dd class="font-medium text-slate-900">{{ adv.phone ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-slate-500">Category</dt><dd class="font-medium text-slate-900">{{ adv.category ?? '—' }}</dd></div>
                        </dl>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Company</h2>
                        <dl v-if="company" class="mt-3 space-y-2 text-sm">
                            <div class="flex justify-between gap-4"><dt class="text-slate-500">Trade name</dt><dd class="font-medium text-slate-900">{{ company.name }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-slate-500">CR number</dt><dd class="font-medium text-slate-900">{{ company.compliance.cr_number ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-slate-500">VAT</dt><dd class="font-medium text-slate-900">{{ company.compliance.vat_number ?? '—' }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-slate-500">Owners</dt><dd class="font-medium text-slate-900">{{ company.owners.length }}</dd></div>
                        </dl>
                        <p v-else class="mt-3 text-sm text-slate-500">No company linked.</p>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-2">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Content</h2>
                        <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div class="rounded-xl bg-slate-50 p-4"><p class="text-2xl font-bold text-slate-900">{{ adv.content_stats?.total ?? 0 }}</p><p class="text-xs font-medium text-slate-500">Total</p></div>
                            <div class="rounded-xl bg-amber-50 p-4"><p class="text-2xl font-bold text-amber-700">{{ adv.content_stats?.pending ?? 0 }}</p><p class="text-xs font-medium text-amber-600">Pending</p></div>
                            <div class="rounded-xl bg-emerald-50 p-4"><p class="text-2xl font-bold text-emerald-700">{{ adv.content_stats?.approved ?? 0 }}</p><p class="text-xs font-medium text-emerald-600">Approved</p></div>
                            <div class="rounded-xl bg-rose-50 p-4"><p class="text-2xl font-bold text-rose-700">{{ adv.content_stats?.rejected ?? 0 }}</p><p class="text-xs font-medium text-rose-600">Rejected</p></div>
                        </div>
                    </div>
                </div>

                <!-- ACCOUNT -->
                <div v-show="tab === 'account'" class="space-y-5">
                    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-semibold text-slate-950">Account</h2>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <label class="block"><span class="text-sm font-medium text-slate-700">Brand name</span><input v-model="account.brand_name" :disabled="!canManage" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-50"></label>
                            <label class="block"><span class="text-sm font-medium text-slate-700">Contact name</span><input v-model="account.name" :disabled="!canManage" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-50"></label>
                            <label class="block"><span class="text-sm font-medium text-slate-700">Email (login)</span><input :value="adv.email" disabled type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-100 px-3 py-2.5 text-sm text-slate-500"></label>
                            <label class="block"><span class="text-sm font-medium text-slate-700">Phone</span><input v-model="account.phone" :disabled="!canManage" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-50"></label>
                            <label class="block"><span class="text-sm font-medium text-slate-700">Category</span><input v-model="account.category" :disabled="!canManage" type="text" placeholder="e.g. coffee" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-50"></label>
                        </div>
                        <div v-if="canManage" class="mt-5 flex flex-wrap items-center gap-3">
                            <button type="button" :disabled="savingAccount" class="rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-60" @click="saveAccount">{{ savingAccount ? 'Saving…' : 'Save changes' }}</button>
                            <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="resetPw"><KeyRound class="size-4" /> Reset password</button>
                            <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="toggleStatus">{{ adv.status === 'active' ? 'Suspend' : 'Activate' }}</button>
                        </div>
                    </div>
                </div>

                <!-- COMPANY -->
                <div v-show="tab === 'company'" class="space-y-5">
                    <div v-if="company && !editableCompany" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                        This advertiser is linked to a POS merchant. Edit the company on the
                        <RouterLink :to="`/admin/merchants/${company.uuid}`" class="font-semibold underline">Merchants page</RouterLink>.
                    </div>
                    <div v-if="company" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-semibold text-slate-950">Company information</h2>
                        <fieldset :disabled="!editableCompany || !canManage" class="mt-4 space-y-6">
                            <div class="grid gap-4 md:grid-cols-2">
                                <label class="block"><span class="text-sm font-semibold text-slate-700">Trade name</span><input v-model="comp.name" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-teal-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:opacity-70"><p v-if="fieldError('name')" class="mt-1 text-xs text-rose-600">{{ fieldError('name') }}</p></label>
                                <label class="block"><span class="text-sm font-semibold text-slate-700">Trade name (Arabic)</span><input v-model="comp.name_ar" dir="rtl" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-teal-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:opacity-70"></label>
                                <label class="block"><span class="text-sm font-semibold text-slate-700">Legal name</span><input v-model="comp.legal_name" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-teal-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:opacity-70"></label>
                                <label class="block"><span class="text-sm font-semibold text-slate-700">Legal name (Arabic)</span><input v-model="comp.legal_name_ar" dir="rtl" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-teal-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:opacity-70"></label>
                            </div>
                            <div class="grid gap-4 md:grid-cols-3">
                                <label class="block"><span class="text-sm font-semibold text-slate-700">CR number</span><input v-model="comp.compliance.cr_number" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-teal-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:opacity-70"><p v-if="fieldError('compliance.cr_number')" class="mt-1 text-xs text-rose-600">{{ fieldError('compliance.cr_number') }}</p></label>
                                <label class="block"><span class="text-sm font-semibold text-slate-700">CR issue date</span><input v-model="comp.compliance.cr_issue_date" type="date" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-teal-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:opacity-70"></label>
                                <label class="block"><span class="text-sm font-semibold text-slate-700">CR expiry date</span><input v-model="comp.compliance.cr_expiry_date" type="date" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-teal-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:opacity-70"><p v-if="fieldError('compliance.cr_expiry_date')" class="mt-1 text-xs text-rose-600">{{ fieldError('compliance.cr_expiry_date') }}</p></label>
                                <label class="block"><span class="text-sm font-semibold text-slate-700">Establishment date</span><input v-model="comp.compliance.establishment_date" type="date" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-teal-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:opacity-70"></label>
                                <label class="block"><span class="text-sm font-semibold text-slate-700">VAT number</span><input v-model="comp.compliance.vat_number" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-teal-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:opacity-70"></label>
                                <label class="block"><span class="text-sm font-semibold text-slate-700">Tax number</span><input v-model="comp.compliance.tax_number" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-teal-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:opacity-70"></label>
                                <label class="block"><span class="text-sm font-semibold text-slate-700">Chamber of commerce no.</span><input v-model="comp.compliance.chamber_of_commerce_number" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-teal-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:opacity-70"></label>
                                <label class="block"><span class="text-sm font-semibold text-slate-700">Municipality license no.</span><input v-model="comp.compliance.municipality_license_number" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-teal-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:opacity-70"></label>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Contact</h3>
                                <div class="mt-3 grid gap-4 md:grid-cols-3">
                                    <label class="block"><span class="text-sm font-semibold text-slate-700">Name</span><input v-model="comp.contact.name" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-teal-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:opacity-70"></label>
                                    <label class="block"><span class="text-sm font-semibold text-slate-700">Phone</span><input v-model="comp.contact.phone" type="tel" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-teal-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:opacity-70"></label>
                                    <label class="block"><span class="text-sm font-semibold text-slate-700">Email</span><input v-model="comp.contact.email" type="email" class="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm focus:border-teal-500 focus:bg-white focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:opacity-70"></label>
                                </div>
                            </div>
                        </fieldset>
                        <div v-if="editableCompany && canManage" class="mt-5">
                            <button type="button" :disabled="savingCompany" class="rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-60" @click="saveCompany">{{ savingCompany ? 'Saving…' : 'Save company' }}</button>
                        </div>
                    </div>
                </div>

                <!-- OWNERS -->
                <div v-show="tab === 'owners'" class="space-y-5">
                    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex items-center justify-between">
                            <h2 class="text-lg font-semibold text-slate-950">Owners</h2>
                            <button v-if="editableCompany && canManage" type="button" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="addOwner"><Plus class="size-4" /> Add owner</button>
                        </div>
                        <p v-if="fieldError('owners')" class="mt-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700">{{ fieldError('owners') }}</p>
                        <fieldset :disabled="!editableCompany || !canManage" class="mt-4 space-y-4">
                            <div v-for="(o, i) in owners" :key="i" class="rounded-lg border border-slate-200 bg-slate-50/40 p-4" :class="{ 'border-teal-300 bg-teal-50/50': o.is_primary }">
                                <div class="flex items-center justify-between gap-3 border-b border-slate-200 pb-3">
                                    <span class="text-sm font-semibold text-slate-700">Owner #{{ i + 1 }}</span>
                                    <div class="flex items-center gap-3">
                                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-600"><input type="radio" name="primary_owner" :checked="o.is_primary" @change="setPrimaryOwner(i)"> Primary</label>
                                        <button v-if="owners.length > 1" type="button" class="grid size-8 place-items-center rounded-lg text-rose-600 hover:bg-rose-50" @click="removeOwner(i)"><Trash2 class="size-4" /></button>
                                    </div>
                                </div>
                                <div class="mt-4 grid gap-4 md:grid-cols-2">
                                    <label class="block"><span class="text-sm font-semibold text-slate-700">Full name (English)</span><input v-model="o.full_name_en" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"><p v-if="fieldError(`owners.${i}.full_name_en`)" class="mt-1 text-xs text-rose-600">{{ fieldError(`owners.${i}.full_name_en`) }}</p></label>
                                    <label class="block"><span class="text-sm font-semibold text-slate-700">Full name (Arabic)</span><input v-model="o.full_name_ar" dir="rtl" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"></label>
                                    <label class="block"><span class="text-sm font-semibold text-slate-700">Civil ID</span><input v-model="o.civil_id" type="text" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"></label>
                                    <label class="block"><span class="text-sm font-semibold text-slate-700">Nationality</span><select v-model="o.nationality" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"><option value="">—</option><option v-for="c in countries" :key="c.code" :value="c.code">{{ c.name_en }}</option></select></label>
                                    <label class="block"><span class="text-sm font-semibold text-slate-700">Phone</span><input v-model="o.phone" type="tel" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"></label>
                                    <label class="block"><span class="text-sm font-semibold text-slate-700">Email</span><input v-model="o.email" type="email" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"></label>
                                </div>
                            </div>
                        </fieldset>
                        <div v-if="editableCompany && canManage" class="mt-5">
                            <button type="button" :disabled="savingOwners" class="rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-60" @click="saveOwners">{{ savingOwners ? 'Saving…' : 'Save owners' }}</button>
                        </div>
                    </div>
                </div>

                <!-- ACTIVITIES -->
                <div v-show="tab === 'activities'" class="space-y-5">
                    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-semibold text-slate-950">Business activity</h2>
                        <p class="mt-1 text-sm text-slate-500">Drives slider filtration and competitor warnings.</p>
                        <div v-if="availableActivities.length === 0" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">Loading activities…</div>
                        <template v-else>
                            <div class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                <button v-for="a in availableActivities" :key="a.id" type="button" :disabled="!editableCompany || !canManage" class="flex flex-col items-start gap-1 rounded-lg border px-3 py-3 text-start text-sm transition disabled:opacity-60" :class="isActivity(a) ? 'border-teal-500 bg-teal-50 text-teal-800' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'" @click="toggleActivity(a)">
                                    <span class="text-xs font-bold uppercase tracking-wide opacity-60">{{ a.code }}</span>
                                    <span class="font-semibold">{{ a.name_en }}</span>
                                </button>
                            </div>
                            <div v-if="selectedActivities.length" class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-4">
                                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Primary activity</p>
                                <ul class="space-y-2">
                                    <li v-for="e in selectedActivities" :key="e.business_activity_id" class="flex items-center justify-between rounded-lg bg-white px-3 py-2 text-sm">
                                        <span class="font-semibold text-slate-800">{{ activityName(e.business_activity_id) }}</span>
                                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-600"><input type="radio" name="primary_activity" :checked="e.is_primary" :disabled="!editableCompany || !canManage" @change="setPrimaryActivity(e.business_activity_id)"> Primary</label>
                                    </li>
                                </ul>
                            </div>
                            <div v-if="editableCompany && canManage" class="mt-5">
                                <button type="button" :disabled="savingActivities" class="rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:opacity-60" @click="saveActivities">{{ savingActivities ? 'Saving…' : 'Save activities' }}</button>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- CONTENT -->
                <div v-show="tab === 'content'" class="space-y-4">
                    <div class="inline-flex rounded-lg border border-slate-200 bg-white p-1 shadow-sm">
                        <button type="button" class="rounded-md px-5 py-2 text-sm font-semibold transition" :class="contentTab === 'pending' ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-50'" @click="contentTab = 'pending'">Pending</button>
                        <button type="button" class="rounded-md px-5 py-2 text-sm font-semibold transition" :class="contentTab === 'reviewed' ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-50'" @click="contentTab = 'reviewed'">Reviewed</button>
                    </div>
                    <div v-if="contentLoading" class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-sm text-slate-500 shadow-sm">Loading…</div>
                    <div v-else-if="content.length === 0" class="flex flex-col items-center gap-2 rounded-2xl border border-slate-200 bg-white p-12 text-center text-slate-500 shadow-sm"><Megaphone class="size-8 text-slate-300" /><p class="text-sm font-semibold">No content here.</p></div>
                    <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <button v-for="c in content" :key="c.id" type="button" class="group overflow-hidden rounded-2xl border border-slate-200 bg-white text-left shadow-sm transition hover:-translate-y-0.5 hover:border-teal-300 hover:shadow-md" @click="lightbox = c">
                            <div class="relative grid h-40 place-items-center bg-slate-900">
                                <img v-if="c.type === 'image' && c.url" :src="c.url" :alt="c.title" class="size-full object-cover">
                                <img v-else-if="c.thumbnail_url" :src="c.thumbnail_url" :alt="c.title" class="size-full object-cover">
                                <Video v-else class="size-8 text-slate-500" />
                                <span class="absolute inset-0 grid place-items-center bg-slate-950/0 opacity-0 transition group-hover:bg-slate-950/30 group-hover:opacity-100">
                                    <span class="rounded-full bg-white/90 px-3 py-1 text-xs font-semibold text-slate-800">View</span>
                                </span>
                            </div>
                            <div class="flex items-center justify-between gap-2 p-4">
                                <p class="truncate text-sm font-semibold text-slate-950">{{ c.title }}</p>
                                <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider" :class="statusClass[c.status]">{{ c.status }}</span>
                            </div>
                        </button>
                    </div>
                </div>

                <!-- DOCUMENTS (placeholder) -->
                <div v-show="tab === 'documents'" class="grid place-items-center rounded-2xl border border-dashed border-slate-300 bg-white p-12 text-center text-slate-500 shadow-sm">
                    <div><FileText class="mx-auto size-10 text-slate-300" /><p class="mt-3 text-sm font-semibold">Documents</p><p class="mt-1 text-sm">Upload &amp; verify company documents — coming next.</p></div>
                </div>

                <!-- STATUS (placeholder) -->
                <div v-show="tab === 'status'" class="grid place-items-center rounded-2xl border border-dashed border-slate-300 bg-white p-12 text-center text-slate-500 shadow-sm">
                    <div><CheckCircle2 class="mx-auto size-10 text-slate-300" /><p class="mt-3 text-sm font-semibold">Slider delivery status</p><p class="mt-1 max-w-md text-sm">Which slider package, on which device, where it's playing, and minutes run — arrives with the device slider rollout.</p></div>
                </div>
            </template>
        </section>

        <MediaLightbox
            v-if="lightbox"
            :type="lightbox.type"
            :url="lightbox.url"
            :poster="lightbox.thumbnail_url"
            :title="lightbox.title"
            @close="lightbox = null"
        />
    </AdminLayout>
</template>
