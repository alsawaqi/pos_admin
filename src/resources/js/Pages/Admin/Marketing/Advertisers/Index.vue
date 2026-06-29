<script setup lang="ts">
/**
 * Marketing → Advertisers — admin-driven onboarding for the marketing
 * platform. Create an advertiser account + login, optionally link it to a POS
 * merchant, suspend / reactivate, and reset the password (revealed once). The
 * advertiser then logs in on the marketing portal to upload content.
 *
 * Strings are inline English for now (admin-internal, English-first); i18n keys
 * can be added in a follow-up the way Business Activities does.
 */

import { KeyRound, Megaphone, Pencil, Plus, Search } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import BaseModal from '@/Components/BaseModal.vue';
import { ApiError } from '@/lib/api';
import {
    createAdvertiser,
    listAdvertisers,
    listMerchantCompanies,
    resetAdvertiserPassword,
    updateAdvertiser,
    type Advertiser,
    type AdvertiserStatus,
    type UpdateAdvertiserPayload,
} from '@/lib/api/marketingAdvertisers';
import type { MerchantListItem } from '@/lib/api/merchants';
import { usePermissions } from '@/composables/usePermissions';
import { PlatformPermission } from '@/lib/permissions';

const { can } = usePermissions();
const canManage = computed(() => can(PlatformPermission.MarketingAdvertisersManage));

const router = useRouter();
const route = useRoute();

function newAdvertiser(): void {
    void router.push('/admin/marketing/advertisers/new');
}

/**
 * True while editing an advertising-only advertiser (links to a dedicated
 * is_advertiser_only company, not a POS merchant). Those keep their company
 * link, so the edit modal hides the merchant toggle and omits is_merchant /
 * company_id from the update payload.
 */
const editingAdvertiserOnly = ref(false);
const editingCompanyName = ref<string | null>(null);

// --- Page state ----------------------------------------------------
const advertisers = ref<Advertiser[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const flash = ref<{ type: 'success' | 'error'; text: string } | null>(null);
// Plaintext password revealed once after create / reset.
const revealed = ref<{ brand: string; password: string } | null>(null);

// Filters.
const search = ref('');
const statusFilter = ref<AdvertiserStatus | ''>('');
const merchantsOnly = ref(false);

// Merchant company picker (lazy-loaded the first time it's needed).
const companies = ref<MerchantListItem[]>([]);
const companiesLoaded = ref(false);

// --- Modal state (shared by Create + Edit) -------------------------
const modalOpen = ref(false);
const modalMode = ref<'create' | 'edit'>('create');
const editingId = ref<number | null>(null);
const submitting = ref(false);
const fieldErrors = ref<Record<string, string[]>>({});
const modalError = ref<string | null>(null);

const form = reactive<{
    name: string;
    brand_name: string;
    email: string;
    password: string;
    phone: string;
    is_merchant: boolean;
    company_id: number | null;
    category: string;
}>({
    name: '',
    brand_name: '',
    email: '',
    password: '',
    phone: '',
    is_merchant: false,
    company_id: null,
    category: '',
});

function openEdit(advertiser: Advertiser): void {
    modalMode.value = 'edit';
    editingId.value = advertiser.id;
    // Advertising-only: not a merchant, yet linked to its own company record.
    editingAdvertiserOnly.value = !advertiser.is_merchant && advertiser.company !== null;
    editingCompanyName.value = advertiser.company?.name ?? null;
    form.name = advertiser.name;
    form.brand_name = advertiser.brand_name;
    form.email = advertiser.email;
    form.password = '';
    form.phone = advertiser.phone ?? '';
    form.is_merchant = advertiser.is_merchant;
    form.company_id = advertiser.company_id;
    form.category = advertiser.category ?? '';
    fieldErrors.value = {};
    modalError.value = null;
    if (form.is_merchant) void ensureCompanies();
    modalOpen.value = true;
}

function closeModal(): void {
    modalOpen.value = false;
    editingAdvertiserOnly.value = false;
}

async function ensureCompanies(): Promise<void> {
    if (companiesLoaded.value) return;
    try {
        const res = await listMerchantCompanies();
        companies.value = res.data;
        companiesLoaded.value = true;
    } catch {
        // Non-fatal — the picker just stays empty; the field validates server-side.
    }
}

watch(() => form.is_merchant, (on) => {
    if (on) {
        void ensureCompanies();
    } else {
        form.company_id = null;
    }
});

// --- Data loading --------------------------------------------------
async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const res = await listAdvertisers({
            search: search.value || undefined,
            status: statusFilter.value || undefined,
            merchants_only: merchantsOnly.value || undefined,
        });
        advertisers.value = res.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load advertisers';
    } finally {
        loading.value = false;
    }
}

let debounceTimer: number | null = null;
watch([search, statusFilter, merchantsOnly], () => {
    if (debounceTimer) window.clearTimeout(debounceTimer);
    debounceTimer = window.setTimeout(() => void load(), 250);
});

onMounted(() => {
    // Returning from the onboarding wizard (?created=<id>) — confirm + tidy URL.
    if (route.query.created) {
        flash.value = { type: 'success', text: 'Advertiser created.' };
        void router.replace('/admin/marketing/advertisers');
    }
    void load();
});

// --- Submit (Create or Edit) ---------------------------------------
async function submit(): Promise<void> {
    submitting.value = true;
    fieldErrors.value = {};
    modalError.value = null;

    try {
        if (modalMode.value === 'create') {
            const res = await createAdvertiser({
                name: form.name,
                brand_name: form.brand_name,
                email: form.email,
                password: form.password,
                phone: form.phone || null,
                is_merchant: form.is_merchant,
                company_id: form.is_merchant ? form.company_id : null,
                category: form.category || null,
            });
            flash.value = { type: 'success', text: 'Advertiser created.' };
            revealed.value = { brand: res.data.brand_name, password: form.password };
        } else if (editingId.value !== null) {
            const payload: UpdateAdvertiserPayload = {
                name: form.name,
                brand_name: form.brand_name,
                phone: form.phone || null,
                category: form.category || null,
            };
            // For an advertising-only advertiser, leave the company link alone
            // (omit is_merchant / company_id) so it isn't wiped on a profile
            // edit. Otherwise apply the merchant toggle as set.
            if (!editingAdvertiserOnly.value) {
                payload.is_merchant = form.is_merchant;
                payload.company_id = form.is_merchant ? form.company_id : null;
            }
            await updateAdvertiser(editingId.value, payload);
            flash.value = { type: 'success', text: 'Advertiser updated.' };
        }
        closeModal();
        await load();
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            fieldErrors.value = err.payload.errors;
            modalError.value = 'Please fix the highlighted fields.';
        } else {
            modalError.value = err instanceof Error ? err.message : 'Submission failed';
        }
    } finally {
        submitting.value = false;
    }
}

// --- Quick actions: suspend / activate + reset password ------------
async function toggleStatus(advertiser: Advertiser): Promise<void> {
    const next: AdvertiserStatus = advertiser.status === 'active' ? 'suspended' : 'active';
    try {
        await updateAdvertiser(advertiser.id, { status: next });
        flash.value = { type: 'success', text: next === 'suspended' ? 'Advertiser suspended.' : 'Advertiser reactivated.' };
        await load();
    } catch (err) {
        flash.value = { type: 'error', text: err instanceof Error ? err.message : 'Failed' };
    }
}

async function resetPassword(advertiser: Advertiser): Promise<void> {
    if (!window.confirm(`Reset the password for "${advertiser.brand_name}"? They'll need the new one to log in.`)) {
        return;
    }
    try {
        const res = await resetAdvertiserPassword(advertiser.id);
        revealed.value = { brand: advertiser.brand_name, password: res.data.password };
        flash.value = { type: 'success', text: 'Password reset.' };
    } catch (err) {
        flash.value = { type: 'error', text: err instanceof Error ? err.message : 'Failed to reset password' };
    }
}

function copyPassword(): void {
    if (revealed.value && navigator.clipboard) {
        void navigator.clipboard.writeText(revealed.value.password);
    }
}
</script>

<template>
    <AdminLayout>
        <section class="space-y-6">
            <!-- Header -->
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">Marketing</p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">Advertisers</h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                        Onboard advertisers, link them to a merchant when they're also a POS merchant, and manage their access.
                    </p>
                </div>

                <button
                    v-if="canManage"
                    type="button"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800"
                    @click="newAdvertiser"
                >
                    <Plus class="size-4" />
                    New advertiser
                </button>
            </div>

            <!-- Revealed password banner -->
            <div v-if="revealed" class="rounded-lg border border-teal-200 bg-teal-50 px-4 py-3 text-sm text-teal-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <span class="font-semibold">Password for {{ revealed.brand }}:</span>
                        <code class="ml-2 rounded bg-white px-2 py-1 font-mono text-teal-800 ring-1 ring-teal-200">{{ revealed.password }}</code>
                        <span class="ml-2 text-xs text-teal-700">Copy it now — it won't be shown again.</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" class="rounded-lg border border-teal-300 bg-white px-3 py-1.5 text-xs font-semibold text-teal-800 hover:bg-teal-100" @click="copyPassword">Copy</button>
                        <button type="button" class="rounded-lg px-3 py-1.5 text-xs font-semibold text-teal-700 hover:bg-teal-100" @click="revealed = null">Dismiss</button>
                    </div>
                </div>
            </div>

            <!-- Flash banner -->
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
                        placeholder="Search brand, contact, or email"
                    >
                </label>

                <select
                    v-model="statusFilter"
                    class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                >
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="suspended">Suspended</option>
                </select>

                <label class="flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm">
                    <input v-model="merchantsOnly" type="checkbox" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                    Merchants only
                </label>
            </div>

            <div v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                {{ error }}
            </div>

            <!-- Table -->
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div v-if="loading" class="p-10 text-center text-sm font-medium text-slate-500">Loading…</div>

                <div v-else-if="advertisers.length === 0" class="flex flex-col items-center gap-3 p-12 text-center text-slate-500">
                    <Megaphone class="size-10 text-slate-300" />
                    <p class="text-sm font-semibold">No advertisers yet.</p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">Brand</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">Contact</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">Merchant</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">Category</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">Status</th>
                                <th v-if="canManage" class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="advertiser in advertisers" :key="advertiser.id" class="transition hover:bg-slate-50">
                                <td class="px-5 py-4">
                                    <RouterLink :to="`/admin/marketing/advertisers/${advertiser.id}`" class="text-sm font-semibold text-slate-950 hover:text-teal-700 hover:underline">{{ advertiser.brand_name }}</RouterLink>
                                    <p class="text-xs text-slate-500">{{ advertiser.email }}</p>
                                </td>
                                <td class="px-5 py-4">
                                    <p class="text-sm font-medium text-slate-700">{{ advertiser.name }}</p>
                                    <p class="text-xs text-slate-500">{{ advertiser.phone ?? '—' }}</p>
                                </td>
                                <td class="px-5 py-4 text-sm text-slate-700">
                                    <span v-if="advertiser.is_merchant" class="font-medium">{{ advertiser.company?.name ?? 'Linked' }}</span>
                                    <span v-else-if="advertiser.company" class="inline-flex items-center gap-1.5">
                                        <span class="font-medium">{{ advertiser.company.name }}</span>
                                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">Advertising</span>
                                    </span>
                                    <span v-else class="text-slate-400">—</span>
                                </td>
                                <td class="px-5 py-4 text-sm text-slate-600">{{ advertiser.category ?? '—' }}</td>
                                <td class="px-5 py-4">
                                    <span
                                        class="rounded-full px-3 py-1 text-xs font-bold uppercase tracking-wider"
                                        :class="advertiser.status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'"
                                    >
                                        {{ advertiser.status }}
                                    </span>
                                </td>
                                <td v-if="canManage" class="px-5 py-4">
                                    <div class="flex items-center justify-end gap-2">
                                        <button
                                            type="button"
                                            class="grid size-8 place-items-center rounded-lg text-slate-500 hover:bg-slate-100"
                                            aria-label="Edit advertiser"
                                            @click="openEdit(advertiser)"
                                        >
                                            <Pencil class="size-4" />
                                        </button>
                                        <button
                                            type="button"
                                            class="grid size-8 place-items-center rounded-lg text-slate-500 hover:bg-slate-100"
                                            aria-label="Reset password"
                                            title="Reset password"
                                            @click="resetPassword(advertiser)"
                                        >
                                            <KeyRound class="size-4" />
                                        </button>
                                        <button
                                            type="button"
                                            class="rounded-lg border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                            @click="toggleStatus(advertiser)"
                                        >
                                            {{ advertiser.status === 'active' ? 'Suspend' : 'Activate' }}
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
        <BaseModal
            v-if="modalOpen"
            :title="modalMode === 'create' ? 'New advertiser' : 'Edit advertiser'"
            size="2xl"
            :loading="submitting"
            @close="closeModal"
        >
            <div v-if="modalError" class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                {{ modalError }}
            </div>

            <form id="advertiser-form" class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">Brand / company name *</span>
                        <input v-model="form.brand_name" type="text" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p v-if="fieldErrors.brand_name" class="mt-1 text-xs text-rose-600">{{ fieldErrors.brand_name[0] }}</p>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">Contact name *</span>
                        <input v-model="form.name" type="text" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p v-if="fieldErrors.name" class="mt-1 text-xs text-rose-600">{{ fieldErrors.name[0] }}</p>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">Email *</span>
                        <input v-model="form.email" type="email" :disabled="modalMode === 'edit'" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100 disabled:bg-slate-100 disabled:text-slate-500">
                        <p v-if="modalMode === 'edit'" class="mt-1 text-xs text-slate-400">Email is the login id and can't be changed here.</p>
                        <p v-if="fieldErrors.email" class="mt-1 text-xs text-rose-600">{{ fieldErrors.email[0] }}</p>
                    </label>
                    <label v-if="modalMode === 'create'" class="block">
                        <span class="text-sm font-medium text-slate-700">Password *</span>
                        <input v-model="form.password" type="text" required minlength="8" autocomplete="off" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm font-mono focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p v-if="fieldErrors.password" class="mt-1 text-xs text-rose-600">{{ fieldErrors.password[0] }}</p>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">Phone</span>
                        <input v-model="form.phone" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-slate-700">Category</span>
                        <input v-model="form.category" type="text" placeholder="e.g. coffee, fashion" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <p class="mt-1 text-xs text-slate-400">Used later to flag competitor conflicts when targeting.</p>
                    </label>

                    <!-- Advertising-only advertiser: its company is a dedicated
                         record, not a POS merchant. Show it read-only so a
                         profile edit never disturbs the link. -->
                    <div v-if="editingAdvertiserOnly" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm sm:col-span-2">
                        <span class="text-slate-500">Advertising company:</span>
                        <span class="ml-1 font-semibold text-slate-800">{{ editingCompanyName ?? '—' }}</span>
                    </div>

                    <template v-else>
                        <label class="flex items-center gap-2 sm:col-span-2">
                            <input v-model="form.is_merchant" type="checkbox" class="size-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                            <span class="text-sm font-medium text-slate-700">This advertiser is also a POS merchant</span>
                        </label>

                        <label v-if="form.is_merchant" class="block sm:col-span-2">
                            <span class="text-sm font-medium text-slate-700">Linked merchant *</span>
                            <select v-model="form.company_id" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <option :value="null">— Select a merchant —</option>
                                <option v-for="company in companies" :key="company.id" :value="company.id">{{ company.name }}</option>
                            </select>
                            <p v-if="fieldErrors.company_id" class="mt-1 text-xs text-rose-600">{{ fieldErrors.company_id[0] }}</p>
                        </label>
                    </template>
                </div>
            </form>

            <template #footer>
                <div class="flex items-center justify-end gap-3">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="closeModal">Cancel</button>
                    <button
                        type="submit"
                        form="advertiser-form"
                        :disabled="submitting"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800 disabled:cursor-wait disabled:opacity-70"
                    >
                        {{ submitting ? 'Saving…' : (modalMode === 'create' ? 'Create advertiser' : 'Save changes') }}
                    </button>
                </div>
            </template>
        </BaseModal>
    </AdminLayout>
</template>
