<script setup lang="ts">
import {
    ArrowLeft,
    Ban,
    CheckCircle2,
    FileText,
    History,
    ListTree,
    Loader2,
    Mail,
    PauseCircle,
    PlayCircle,
    RotateCw,
    Trash2,
    Upload,
    UserPlus,
    Users,
    X,
    XCircle,
} from 'lucide-vue-next';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import StatusPill, { type StatusTone } from '@/Components/Admin/StatusPill.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    deleteMerchant,
    deleteMerchantDocument,
    getMerchant,
    listMerchantDocuments,
    merchantDocumentDownloadUrl,
    rejectMerchantDocument,
    transitionMerchantStatus,
    uploadMerchantDocument,
    verifyMerchantDocument,
    type CompanyDocument,
    type CompanyStatus,
    type DocumentType,
    type MerchantDetail,
} from '@/lib/api/merchants';
import {
    createPortalUser,
    listPortalUsers,
    resetPortalUserPassword,
    updatePortalUser,
    type CreateMerchantUserPayload,
    type PortalUser,
    type PortalUserStatus,
} from '@/lib/api/portalUsers';
import ConfirmDialog from '@/Components/Admin/ConfirmDialog.vue';
import { Pencil, Power } from 'lucide-vue-next';
import { deleteBranch, listBranches, type BranchListItem } from '@/lib/api/branches';
// Sprint 2 (slim) — devices listing for the merchant Show page's
// new Devices tab. Reuses the existing /devices index endpoint
// filtered by company_id; no new backend needed.
import { decommissionDevice, listDevices, type DeviceListItem, type DeviceStatus } from '@/lib/api/devices';
import { PlatformPermission } from '@/lib/permissions';
// Country-name lookup used by the owners list — replaces the raw
// ISO-2 code (e.g. "OM") with the localized display name
// (e.g. "Oman" / "عُمان") so the Show page is readable.
import { countryNameForLocale } from '@/lib/countries';

const { t, locale } = useI18n();
const { can } = usePermissions();
const route = useRoute();
const router = useRouter();

const merchant = ref<MerchantDetail | null>(null);
const documents = ref<CompanyDocument[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);
const activeTab = ref<'overview' | 'documents' | 'activities' | 'branches' | 'devices' | 'portal_users' | 'history'>('overview');

// ---- Branches tab state -------------------------------------------------
// Separate from the portal-users branch list (which is a small
// scope picker). This holds the full per-company list rendered
// in the dedicated Branches tab.
const branchesList = ref<BranchListItem[]>([]);
const branchesLoading = ref(false);
const branchesError = ref<string | null>(null);
// Paginated — mirrors the standalone Branches/Index pattern so
// merchants with > 25 branches still load fast.
import type { PaginationMeta } from '@/lib/api/merchants';
const branchesMeta = ref<PaginationMeta | null>(null);
const branchesPage = ref(1);
// Branch delete state — shared confirm dialog across all rows.
const branchDeleteTarget = ref<BranchListItem | null>(null);
const branchDeleting = ref(false);
const branchDeleteError = ref<string | null>(null);

// ---- Devices tab state --------------------------------------------------
// Mirror of branchesList for devices. Tab-scoped so a tab switch
// triggers exactly one fetch each time (no stale data, no double
// loads).
const devicesList = ref<DeviceListItem[]>([]);
const devicesLoading = ref(false);
const devicesError = ref<string | null>(null);
const devicesMeta = ref<PaginationMeta | null>(null);
const devicesPage = ref(1);
// Device decommission state.
const deviceDecommTarget = ref<DeviceListItem | null>(null);
const deviceDecommissioning = ref(false);
const deviceDecommError = ref<string | null>(null);

// ---- Portal Users tab state -------------------------------------------------
// Holds the per-merchant list of portal users + the state for the
// Invite modal (also reused for "Resend invite" prompts).
const portalUsers = ref<PortalUser[]>([]);
const portalLoading = ref(false);
const portalError = ref<string | null>(null);

// Branches list — used to populate the "Branch scope" multi-select
// inside the Invite modal. Loaded once when the tab is opened.
const branches = ref<BranchListItem[]>([]);

// Create-user modal local state. Was previously "invite" — flow
// changed to: admin enters name+email, server generates password,
// SPA shows it ONCE in a follow-up modal for the admin to copy +
// share out of band. No email is sent.
const createOpen = ref(false);
const creating = ref(false);
const createFieldErrors = ref<Record<string, string[]>>({});
const createError = ref<string | null>(null);
const createForm = reactive<CreateMerchantUserPayload>({
    name: '',
    email: '',
    phone: '',
});

// One-shot password modal — shown after either a successful create
// OR a successful password reset. The plaintext lives only in
// memory until the modal closes.
const passwordModalOpen = ref(false);
const passwordModalUser = ref<PortalUser | null>(null);
const passwordModalSecret = ref('');
const passwordCopied = ref(false);

// Per-row action flags (so a click on Reset password on row 7 only
// shows the spinner on row 7, not every row).
const rowBusy = ref<Record<number, boolean>>({});

/**
 * Whether the "+ Invite portal user" button should be enabled.
 * Blueprint §4.5: requires at least one branch + at least one
 * device assigned before the merchant Super Admin can be invited.
 * The Action enforces this server-side too — the UI gate just
 * keeps the button from looking clickable when the API will reject.
 */
const canInvite = computed(() => {
    if (!merchant.value) {
        return false;
    }
    const branchesCount = merchant.value.branches_count ?? 0;
    const devicesCount = merchant.value.devices_count ?? 0;

    return branchesCount > 0 && devicesCount > 0;
});

const uploadForm = ref<{ type: DocumentType; file: File | null; issued_at: string; expires_at: string; notes: string }>({
    type: 'cr_certificate',
    file: null,
    issued_at: '',
    expires_at: '',
    notes: '',
});
const uploading = ref(false);
const uploadError = ref<string | null>(null);

const transitionForm = ref<{ target: CompanyStatus | ''; reason: string }>({ target: '', reason: '' });
const transitioning = ref(false);
const transitionError = ref<string | null>(null);

const documentTypes: { value: DocumentType; label: string }[] = [
    { value: 'cr_certificate', label: 'CR Certificate' },
    { value: 'vat_certificate', label: 'VAT Certificate' },
    { value: 'municipality_license', label: 'Municipality License' },
    { value: 'chamber_certificate', label: 'Chamber Certificate' },
    { value: 'owner_id_card', label: 'Owner ID Card' },
    { value: 'lease_agreement', label: 'Lease Agreement' },
    { value: 'signature_authority', label: 'Signature Authority' },
    { value: 'bank_letter', label: 'Bank Letter' },
    { value: 'other', label: 'Other' },
];

const allowedTransitions = computed<CompanyStatus[]>(() => {
    if (!merchant.value?.status) {
        return [];
    }
    const map: Record<CompanyStatus, CompanyStatus[]> = {
        onboarding: ['active', 'inactive'],
        active: ['suspended', 'inactive'],
        suspended: ['active', 'inactive'],
        inactive: [],
    };
    return map[merchant.value.status] ?? [];
});

const statusTone = computed<StatusTone>(() => {
    const map: Record<CompanyStatus, StatusTone> = {
        onboarding: 'amber',
        active: 'green',
        suspended: 'red',
        inactive: 'slate',
    };

    return map[merchant.value?.status ?? 'onboarding'];
});

function statusLabel(status: CompanyStatus | null | undefined): string {
    if (!status) {
        return '—';
    }
    return status.charAt(0).toUpperCase() + status.slice(1);
}

async function fetchMerchant(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const response = await getMerchant(String(route.params.uuid));
        merchant.value = response.data;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load merchant';
    } finally {
        loading.value = false;
    }
}

async function fetchDocuments(): Promise<void> {
    if (!merchant.value) {
        return;
    }
    const response = await listMerchantDocuments(merchant.value.uuid);
    documents.value = response.data;
}

function onTabChange(tab: typeof activeTab.value): void {
    activeTab.value = tab;
    if (tab === 'documents') {
        void fetchDocuments();
    }
    if (tab === 'portal_users') {
        void fetchPortalUsers();
        void fetchBranchesForScope();
    }
    // Lazy-load the dedicated Branches tab. Re-fetch each time so
    // a returning user sees the current state (someone may have
    // added a branch in another tab/window).
    if (tab === 'branches') {
        void fetchBranchesForTab();
    }
    if (tab === 'devices') {
        void fetchDevicesForTab();
    }
}

/**
 * Fetcher for the Branches tab — uses the existing
 * /admin/api/v1/branches endpoint filtered by company_id. Now
 * paginated (default 25/page) so merchants with many branches
 * stay snappy.
 */
async function fetchBranchesForTab(): Promise<void> {
    if (!merchant.value) {
        return;
    }
    branchesLoading.value = true;
    branchesError.value = null;
    try {
        const response = await listBranches({
            company_id: merchant.value.id,
            page: branchesPage.value,
        });
        branchesList.value = response.data;
        branchesMeta.value = response.meta;
    } catch (err) {
        branchesError.value = err instanceof Error ? err.message : 'Failed to load branches';
    } finally {
        branchesLoading.value = false;
    }
}

/**
 * Fetcher for the Devices tab — same shape as Branches.
 */
async function fetchDevicesForTab(): Promise<void> {
    if (!merchant.value) {
        return;
    }
    devicesLoading.value = true;
    devicesError.value = null;
    try {
        const response = await listDevices({
            company_id: merchant.value.id,
            page: devicesPage.value,
        });
        devicesList.value = response.data;
        devicesMeta.value = response.meta;
    } catch (err) {
        devicesError.value = err instanceof Error ? err.message : 'Failed to load devices';
    } finally {
        devicesLoading.value = false;
    }
}

// ---- Branch delete (from the Branches tab) ----------------------------
function openBranchDelete(row: BranchListItem): void {
    branchDeleteTarget.value = row;
    branchDeleteError.value = null;
}
async function confirmBranchDelete(): Promise<void> {
    if (!branchDeleteTarget.value) {
        return;
    }
    branchDeleting.value = true;
    branchDeleteError.value = null;
    try {
        await deleteBranch(branchDeleteTarget.value.uuid);
        branchDeleteTarget.value = null;
        // Refresh both the tab list AND the merchant header
        // (branches_count is on the merchant detail payload).
        await fetchBranchesForTab();
        await fetchMerchant();
    } catch (err) {
        if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            branchDeleteError.value = String((err.payload as { message?: unknown }).message ?? 'Delete failed');
        } else {
            branchDeleteError.value = err instanceof Error ? err.message : 'Delete failed';
        }
    } finally {
        branchDeleting.value = false;
    }
}

// ---- Merchant delete (header button) ----------------------------------
// Soft-delete the company. Server refuses 409 when active
// branches or devices still exist — surface that inline.
const merchantDeleteOpen = ref(false);
const merchantDeleting = ref(false);
const merchantDeleteError = ref<string | null>(null);

async function confirmMerchantDelete(): Promise<void> {
    if (!merchant.value) {
        return;
    }
    merchantDeleting.value = true;
    merchantDeleteError.value = null;
    try {
        await deleteMerchant(merchant.value.uuid);
        merchantDeleteOpen.value = false;
        await router.push('/admin/merchants');
    } catch (err) {
        if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            merchantDeleteError.value = String((err.payload as { message?: unknown }).message ?? 'Delete failed');
        } else {
            merchantDeleteError.value = err instanceof Error ? err.message : 'Delete failed';
        }
    } finally {
        merchantDeleting.value = false;
    }
}

// ---- Device decommission (from the Devices tab) -----------------------
function openDeviceDecommission(row: DeviceListItem): void {
    deviceDecommTarget.value = row;
    deviceDecommError.value = null;
}
async function confirmDeviceDecommission(): Promise<void> {
    if (!deviceDecommTarget.value) {
        return;
    }
    deviceDecommissioning.value = true;
    deviceDecommError.value = null;
    try {
        await decommissionDevice(deviceDecommTarget.value.uuid);
        deviceDecommTarget.value = null;
        await fetchDevicesForTab();
        await fetchMerchant();
    } catch (err) {
        if (err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            deviceDecommError.value = String((err.payload as { message?: unknown }).message ?? 'Decommission failed');
        } else {
            deviceDecommError.value = err instanceof Error ? err.message : 'Decommission failed';
        }
    } finally {
        deviceDecommissioning.value = false;
    }
}

/**
 * Tone mapping for device status pills on the Devices tab.
 * Mirrors the colours used on the Devices Index page so the
 * platform feels coherent.
 */
function deviceStatusTone(value: DeviceStatus | null): StatusTone {
    switch (value) {
        case 'active': return 'green';
        case 'assigned': return 'sky';
        case 'registered': return 'slate';
        case 'inactive': return 'amber';
        case 'blocked': return 'rose';
        default: return 'slate';
    }
}

function deviceStatusLabel(value: DeviceStatus | null): string {
    if (!value) {
        return '—';
    }
    const key = `devices.status_options.${value}`;
    const translated = t(key);
    return translated === key ? value : translated;
}

// ---- Portal Users tab — fetchers & actions -----------------------------
async function fetchPortalUsers(): Promise<void> {
    if (!merchant.value) {
        return;
    }
    portalLoading.value = true;
    portalError.value = null;
    try {
        const response = await listPortalUsers(merchant.value.uuid);
        portalUsers.value = response.data;
    } catch (err) {
        portalError.value = err instanceof Error ? err.message : 'Failed to load portal users';
    } finally {
        portalLoading.value = false;
    }
}

/**
 * Load the merchant's branches so the Invite modal can populate
 * its branch-scope multi-select. Cached on the page — no need to
 * refetch every time the modal opens.
 */
async function fetchBranchesForScope(): Promise<void> {
    if (!merchant.value || branches.value.length > 0) {
        return;
    }
    try {
        const response = await listBranches({
            company_id: merchant.value.id,
            per_page: 100,
        });
        branches.value = response.data;
    } catch {
        // Silent fail — the scope multi-select just shows empty.
    }
}

function openCreate(): void {
    createForm.name = '';
    createForm.email = '';
    createForm.phone = '';
    createFieldErrors.value = {};
    createError.value = null;
    createOpen.value = true;
}

function closeCreate(): void {
    createOpen.value = false;
}

async function submitCreate(): Promise<void> {
    if (!merchant.value) {
        return;
    }
    creating.value = true;
    createFieldErrors.value = {};
    createError.value = null;

    try {
        const response = await createPortalUser(merchant.value.uuid, {
            name: createForm.name,
            email: createForm.email,
            phone: createForm.phone || null,
        });
        // Close create modal, open the one-shot password modal.
        // The table refresh waits until the password modal closes
        // so the admin always finishes the copy-then-share flow
        // before navigating away.
        closeCreate();
        passwordModalUser.value = response.data;
        passwordModalSecret.value = response.plaintext_password;
        passwordCopied.value = false;
        passwordModalOpen.value = true;
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            createFieldErrors.value = err.payload.errors;
            createError.value = t('merchants.portal_users.create.validation_summary');
        } else if (err instanceof ApiError && err.status === 422 && err.payload && typeof err.payload === 'object' && 'message' in err.payload) {
            // Server-side "no branch / no device" gate surfaces here.
            createError.value = String((err.payload as { message?: unknown }).message);
        } else {
            createError.value = err instanceof Error ? err.message : 'Create failed';
        }
    } finally {
        creating.value = false;
    }
}

/**
 * Click handler for the "Reset password" button on a row.
 * Replaces the obsolete "Resend invite" — generates a fresh
 * password server-side and pops the one-shot password modal.
 */
async function onResetPassword(user: PortalUser): Promise<void> {
    if (!merchant.value) {
        return;
    }
    rowBusy.value[user.id] = true;
    try {
        const response = await resetPortalUserPassword(merchant.value.uuid, user.id);
        passwordModalUser.value = response.data;
        passwordModalSecret.value = response.plaintext_password;
        passwordCopied.value = false;
        passwordModalOpen.value = true;
    } catch (err) {
        portalError.value = err instanceof Error ? err.message : 'Reset failed';
    } finally {
        rowBusy.value[user.id] = false;
    }
}

async function copyPortalPassword(): Promise<void> {
    if (!passwordModalSecret.value) {
        return;
    }
    try {
        await navigator.clipboard.writeText(passwordModalSecret.value);
        passwordCopied.value = true;
        window.setTimeout(() => { passwordCopied.value = false; }, 2000);
    } catch {
        // Clipboard API blocked (insecure context / permission) —
        // select the text so the user can ctrl-C manually.
        const el = document.getElementById('portal-user-password-out');
        if (el instanceof HTMLInputElement) {
            el.select();
        }
    }
}

function closePasswordModal(): void {
    passwordModalOpen.value = false;
    passwordModalUser.value = null;
    passwordModalSecret.value = '';
    // NOW refresh the list to pick up the new (or modified) row.
    void fetchPortalUsers();
}

/**
 * Suspend / reactivate toggle. Flips the user's status without
 * leaving the page — the resulting `PortalUser` row replaces the
 * stale one in place.
 */
async function toggleStatus(user: PortalUser): Promise<void> {
    if (!merchant.value) {
        return;
    }
    const next: PortalUserStatus = user.status === 'suspended' ? 'active' : 'suspended';
    rowBusy.value[user.id] = true;
    try {
        await updatePortalUser(merchant.value.uuid, user.id, { status: next });
        await fetchPortalUsers();
    } catch (err) {
        portalError.value = err instanceof Error ? err.message : 'Status change failed';
    } finally {
        rowBusy.value[user.id] = false;
    }
}

function portalStatusTone(status: PortalUserStatus | null): StatusTone {
    if (status === 'active') return 'green';
    if (status === 'suspended') return 'rose';

    return 'slate';
}

function onFileChange(event: Event): void {
    const target = event.target as HTMLInputElement;
    uploadForm.value.file = target.files?.[0] ?? null;
}

async function submitUpload(): Promise<void> {
    if (!merchant.value || !uploadForm.value.file) {
        uploadError.value = t('merchants.documents.errors.no_file');
        return;
    }

    uploading.value = true;
    uploadError.value = null;

    try {
        await uploadMerchantDocument(merchant.value.uuid, uploadForm.value.file, uploadForm.value.type, {
            issued_at: uploadForm.value.issued_at || undefined,
            expires_at: uploadForm.value.expires_at || undefined,
            notes: uploadForm.value.notes || undefined,
        });
        uploadForm.value.file = null;
        uploadForm.value.issued_at = '';
        uploadForm.value.expires_at = '';
        uploadForm.value.notes = '';
        const fileInput = document.querySelector<HTMLInputElement>('#document-file-input');
        if (fileInput) {
            fileInput.value = '';
        }
        await fetchDocuments();
    } catch (err) {
        const payload = (err as { payload?: { errors?: Record<string, string[]> } }).payload;
        uploadError.value = payload?.errors
            ? Object.values(payload.errors).flat()[0] ?? 'Upload failed'
            : err instanceof Error ? err.message : 'Upload failed';
    } finally {
        uploading.value = false;
    }
}

async function verifyDocument(doc: CompanyDocument): Promise<void> {
    if (!merchant.value) {
        return;
    }
    await verifyMerchantDocument(merchant.value.uuid, doc.uuid);
    await fetchDocuments();
}

async function rejectDocument(doc: CompanyDocument): Promise<void> {
    if (!merchant.value) {
        return;
    }
    const reason = window.prompt(t('merchants.documents.reject_prompt'));
    if (!reason) {
        return;
    }
    await rejectMerchantDocument(merchant.value.uuid, doc.uuid, { reason });
    await fetchDocuments();
}

async function removeDocument(doc: CompanyDocument): Promise<void> {
    if (!merchant.value) {
        return;
    }
    if (!window.confirm(t('merchants.documents.delete_confirm'))) {
        return;
    }
    await deleteMerchantDocument(merchant.value.uuid, doc.uuid);
    await fetchDocuments();
}

async function submitTransition(): Promise<void> {
    if (!merchant.value || !transitionForm.value.target) {
        return;
    }

    transitioning.value = true;
    transitionError.value = null;

    try {
        const response = await transitionMerchantStatus(merchant.value.uuid, {
            target_status: transitionForm.value.target,
            reason: transitionForm.value.reason || undefined,
        });
        merchant.value = response.data;
        transitionForm.value.target = '';
        transitionForm.value.reason = '';
    } catch (err) {
        if (err instanceof ApiError) {
            transitionError.value = err.firstValidationMessage() ?? (typeof err.payload === 'object' && err.payload !== null && 'message' in err.payload ? String((err.payload as { message: unknown }).message) : err.message);
        } else {
            transitionError.value = err instanceof Error ? err.message : 'Status transition failed';
        }
    } finally {
        transitioning.value = false;
    }
}

function documentStatusTone(doc: CompanyDocument): StatusTone {
    const map: Record<typeof doc.verification_status, StatusTone> = {
        pending: 'amber',
        verified: 'green',
        rejected: 'red',
        expired: 'slate',
    };

    return map[doc.verification_status] ?? 'slate';
}

onMounted(() => void fetchMerchant());
</script>

<template>
    <AdminLayout>
        <div v-if="loading" class="flex items-center justify-center gap-3 py-20 text-slate-500">
            <Loader2 class="size-5 animate-spin" />
            <span>{{ t('common.loading') }}</span>
        </div>

        <div v-else-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
            {{ error }}
        </div>

        <section v-else-if="merchant" class="space-y-6">
            <div class="flex items-center justify-between">
                <button type="button" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-500 transition hover:text-slate-800" @click="router.back()">
                    <ArrowLeft class="size-4 rtl:rotate-180" />
                    {{ t('common.back') }}
                </button>

                <!-- Delete merchant — destructive top-right action.
                     Server refuses (409) when active branches/devices
                     exist so the cleanup ordering stays visible to
                     the admin. -->
                <button
                    v-if="can(PlatformPermission.MerchantsDelete)"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg border border-rose-300 bg-rose-50 px-3 py-1.5 text-sm font-semibold text-rose-700 hover:bg-rose-100"
                    @click="merchantDeleteOpen = true"
                >
                    <Trash2 class="size-4" />
                    {{ t('common.delete') }}
                </button>
            </div>

            <header class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                        {{ t('merchants.section_label') }}
                    </p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">{{ merchant.name }}</h1>
                    <p v-if="merchant.name_ar" dir="rtl" class="mt-1 text-lg text-slate-600">{{ merchant.name_ar }}</p>
                    <div class="mt-3 flex flex-wrap items-center gap-2 text-xs font-semibold text-slate-500">
                        <StatusPill :label="statusLabel(merchant.status)" :tone="statusTone" />
                        <span class="text-slate-400">·</span>
                        <span>CR {{ merchant.cr_number }}</span>
                        <span v-if="merchant.vat_number" class="text-slate-400">·</span>
                        <span v-if="merchant.vat_number">VAT {{ merchant.vat_number }}</span>
                    </div>
                </div>

                <div
                    v-if="can(PlatformPermission.MerchantsTransitionStatus) && allowedTransitions.length > 0"
                    class="flex flex-col gap-2 rounded-lg border border-slate-200 bg-white p-4 shadow-sm lg:w-80"
                >
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.status_panel.title') }}</p>
                    <select
                        v-model="transitionForm.target"
                        class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100"
                    >
                        <option value="">{{ t('merchants.status_panel.select_placeholder') }}</option>
                        <option v-for="t in allowedTransitions" :key="t" :value="t">{{ statusLabel(t) }}</option>
                    </select>
                    <input
                        v-if="transitionForm.target === 'suspended' || transitionForm.target === 'inactive'"
                        v-model="transitionForm.reason"
                        type="text"
                        class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-medium text-slate-950 outline-none focus:border-teal-500 focus:bg-white focus:ring-4 focus:ring-teal-100"
                        :placeholder="t('merchants.status_panel.reason_placeholder')"
                    >
                    <button
                        type="button"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white shadow transition hover:bg-slate-800 disabled:opacity-60"
                        :disabled="!transitionForm.target || transitioning"
                        @click="submitTransition"
                    >
                        <PlayCircle v-if="transitionForm.target === 'active'" class="size-4" />
                        <PauseCircle v-else-if="transitionForm.target === 'suspended'" class="size-4" />
                        <XCircle v-else class="size-4" />
                        {{ transitioning ? t('merchants.status_panel.submitting') : t('merchants.status_panel.submit') }}
                    </button>
                    <p v-if="transitionError" class="text-xs font-medium text-rose-700">{{ transitionError }}</p>
                </div>
            </header>

            <nav class="flex gap-2 overflow-x-auto border-b border-slate-200">
                <button
                    v-for="tab in [
                        { key: 'overview', label: t('merchants.tabs.overview') },
                        { key: 'documents', label: t('merchants.tabs.documents') },
                        { key: 'activities', label: t('merchants.tabs.activities') },
                        // Branches tab — gated by BranchesView. Same
                        // pattern as Portal Users below: client-side
                        // hides the chip when the user can't see the
                        // server data anyway.
                        ...(can(PlatformPermission.BranchesView)
                            ? [{ key: 'branches', label: t('merchants.tabs.branches') }]
                            : []),
                        // Devices tab — gated by DevicesView.
                        ...(can(PlatformPermission.DevicesView)
                            ? [{ key: 'devices', label: t('merchants.tabs.devices') }]
                            : []),
                        // Portal Users tab — only shown to admins
                        // with MerchantUsersView. Gated client-side
                        // here AND server-side by PortalUserPolicy.
                        ...(can(PlatformPermission.MerchantUsersView)
                            ? [{ key: 'portal_users', label: t('merchants.tabs.portal_users') }]
                            : []),
                        { key: 'history', label: t('merchants.tabs.history') },
                    ] as const"
                    :key="tab.key"
                    type="button"
                    class="border-b-2 px-4 py-3 text-sm font-semibold transition"
                    :class="activeTab === tab.key ? 'border-teal-600 text-teal-700' : 'border-transparent text-slate-500 hover:text-slate-800'"
                    @click="onTabChange(tab.key)"
                >
                    {{ tab.label }}
                </button>
            </nav>

            <section v-if="activeTab === 'overview'" class="grid gap-6 lg:grid-cols-2">
                <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.overview.compliance') }}</h3>
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                        <dt class="text-slate-500">{{ t('merchants.fields.cr_number') }}</dt>
                        <dd class="font-semibold text-slate-950">{{ merchant.compliance.cr_number ?? '—' }}</dd>
                        <dt class="text-slate-500">{{ t('merchants.fields.cr_issue_date') }}</dt>
                        <dd class="font-semibold text-slate-950">{{ merchant.compliance.cr_issue_date ?? '—' }}</dd>
                        <dt class="text-slate-500">{{ t('merchants.fields.cr_expiry_date') }}</dt>
                        <dd class="font-semibold text-slate-950">{{ merchant.compliance.cr_expiry_date ?? '—' }}</dd>
                        <dt class="text-slate-500">{{ t('merchants.fields.establishment_date') }}</dt>
                        <dd class="font-semibold text-slate-950">{{ merchant.compliance.establishment_date ?? '—' }}</dd>
                        <dt class="text-slate-500">{{ t('merchants.fields.vat_number') }}</dt>
                        <dd class="font-semibold text-slate-950">{{ merchant.compliance.vat_number ?? '—' }}</dd>
                        <dt class="text-slate-500">{{ t('merchants.fields.tax_number') }}</dt>
                        <dd class="font-semibold text-slate-950">{{ merchant.compliance.tax_number ?? '—' }}</dd>
                        <dt class="text-slate-500">{{ t('merchants.fields.chamber_number') }}</dt>
                        <dd class="font-semibold text-slate-950">{{ merchant.compliance.chamber_of_commerce_number ?? '—' }}</dd>
                        <dt class="text-slate-500">{{ t('merchants.fields.municipality_number') }}</dt>
                        <dd class="font-semibold text-slate-950">{{ merchant.compliance.municipality_license_number ?? '—' }}</dd>
                    </dl>
                </div>

                <!-- Owners — one card per row, primary first. The
                     primary badge highlights the canonical owner of
                     record. Owners is always at least 1 (server
                     guarantees) but we still guard with `?.length`
                     in case the relation didn't preload. -->
                <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.overview.owners') }}</h3>

                    <div v-if="(merchant.owners?.length ?? 0) === 0" class="mt-4 text-sm text-slate-500">
                        {{ t('merchants.overview.no_owners') }}
                    </div>

                    <ul v-else class="mt-4 space-y-3">
                        <li
                            v-for="owner in merchant.owners"
                            :key="owner.id ?? owner.full_name_en"
                            class="rounded-lg border border-slate-200 px-4 py-3"
                            :class="{ 'border-teal-300 bg-teal-50/40': owner.is_primary }"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-semibold text-slate-950">{{ owner.full_name_en }}</p>
                                    <p v-if="owner.full_name_ar" dir="rtl" class="text-sm text-slate-500">{{ owner.full_name_ar }}</p>
                                </div>
                                <span
                                    v-if="owner.is_primary"
                                    class="rounded-full bg-teal-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-teal-700"
                                >
                                    {{ t('merchants.overview.owner_primary_badge') }}
                                </span>
                            </div>

                            <dl class="mt-3 grid grid-cols-2 gap-y-2 gap-x-4 text-xs">
                                <div v-if="owner.civil_id">
                                    <dt class="text-slate-500">{{ t('merchants.fields.civil_id') }}</dt>
                                    <dd class="font-semibold text-slate-800">{{ owner.civil_id }}</dd>
                                </div>
                                <div v-if="owner.nationality">
                                    <dt class="text-slate-500">{{ t('merchants.fields.nationality') }}</dt>
                                    <!-- Resolve ISO-2 code → display
                                         name in the current UI locale.
                                         Falls back to the raw code if
                                         the catalogue doesn't know it
                                         (e.g. legacy CR records). -->
                                    <dd class="font-semibold text-slate-800">{{ countryNameForLocale(owner.nationality, locale) }}</dd>
                                </div>
                                <div v-if="owner.phone">
                                    <dt class="text-slate-500">{{ t('merchants.fields.owner_phone') }}</dt>
                                    <dd class="font-semibold text-slate-800">{{ owner.phone }}</dd>
                                </div>
                                <div v-if="owner.email">
                                    <dt class="text-slate-500">{{ t('merchants.fields.owner_email') }}</dt>
                                    <dd class="font-semibold text-slate-800">{{ owner.email }}</dd>
                                </div>
                                <div v-if="owner.ownership_percentage !== null">
                                    <dt class="text-slate-500">{{ t('merchants.fields.ownership_percentage') }}</dt>
                                    <dd class="font-semibold text-slate-800">{{ owner.ownership_percentage }}%</dd>
                                </div>
                            </dl>
                        </li>
                    </ul>

                    <!-- Contact (separate from owners — the contact
                         is the day-to-day point of contact at the
                         business, may differ from the legal owner). -->
                    <div class="mt-6 border-t border-slate-200 pt-4">
                        <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.overview.contact') }}</h4>
                        <dl class="mt-3 grid grid-cols-2 gap-y-2 gap-x-4 text-xs">
                            <div>
                                <dt class="text-slate-500">{{ t('merchants.fields.contact_name') }}</dt>
                                <dd class="font-semibold text-slate-800">{{ merchant.contact.name ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-slate-500">{{ t('merchants.fields.contact_email') }}</dt>
                                <dd class="font-semibold text-slate-800">{{ merchant.contact.email ?? '—' }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </section>

            <section v-if="activeTab === 'documents'" class="space-y-6">
                <div v-if="can(PlatformPermission.MerchantDocumentsUpload)" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.documents.upload_title') }}</h3>
                    <div class="mt-4 grid gap-3 md:grid-cols-2 lg:grid-cols-4">
                        <select
                            v-model="uploadForm.type"
                            class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-medium"
                        >
                            <option v-for="opt in documentTypes" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                        </select>
                        <input
                            id="document-file-input"
                            type="file"
                            accept="application/pdf,image/jpeg,image/png"
                            class="text-sm"
                            @change="onFileChange"
                        >
                        <input v-model="uploadForm.issued_at" type="date" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                        <input v-model="uploadForm.expires_at" type="date" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                    </div>
                    <button
                        type="button"
                        class="mt-4 inline-flex items-center gap-2 rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white shadow transition hover:bg-slate-800 disabled:opacity-60"
                        :disabled="uploading || !uploadForm.file"
                        @click="submitUpload"
                    >
                        <Upload class="size-4" />
                        {{ uploading ? t('merchants.documents.uploading') : t('merchants.documents.upload_button') }}
                    </button>
                    <p v-if="uploadError" class="mt-2 text-xs font-medium text-rose-700">{{ uploadError }}</p>
                </div>

                <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <div v-if="documents.length === 0" class="flex flex-col items-center gap-3 p-10 text-slate-500">
                        <FileText class="size-8 text-slate-300" />
                        <p class="text-sm font-medium">{{ t('merchants.documents.empty') }}</p>
                    </div>

                    <ul v-else class="divide-y divide-slate-100">
                        <li v-for="doc in documents" :key="doc.id" class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <p class="font-semibold text-slate-950">{{ doc.original_name }}</p>
                                <p class="text-xs text-slate-500">
                                    {{ doc.document_type.replace(/_/g, ' ') }} ·
                                    {{ (doc.size_bytes / 1024).toFixed(1) }} KB
                                    <span v-if="doc.expires_at">· {{ t('merchants.documents.expires_on', { date: doc.expires_at }) }}</span>
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <StatusPill :label="doc.verification_status" :tone="documentStatusTone(doc)" />
                                <a
                                    :href="merchantDocumentDownloadUrl(merchant.uuid, doc.uuid)"
                                    class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                >
                                    {{ t('merchants.documents.download') }}
                                </a>
                                <button
                                    v-if="can(PlatformPermission.MerchantDocumentsVerify) && doc.verification_status !== 'verified'"
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100"
                                    @click="verifyDocument(doc)"
                                >
                                    <CheckCircle2 class="size-3.5" />
                                    {{ t('merchants.documents.verify') }}
                                </button>
                                <button
                                    v-if="can(PlatformPermission.MerchantDocumentsVerify) && doc.verification_status !== 'rejected'"
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded-lg bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-100"
                                    @click="rejectDocument(doc)"
                                >
                                    <XCircle class="size-3.5" />
                                    {{ t('merchants.documents.reject') }}
                                </button>
                                <button
                                    v-if="can(PlatformPermission.MerchantDocumentsVerify)"
                                    type="button"
                                    class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                                    @click="removeDocument(doc)"
                                >
                                    {{ t('common.delete') }}
                                </button>
                            </div>
                        </li>
                    </ul>
                </div>
            </section>

            <section v-if="activeTab === 'activities'" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.activities.title') }}</h3>
                    <ListTree class="size-5 text-slate-400" />
                </div>

                <div v-if="merchant.activities.length === 0" class="mt-6 rounded-lg border border-dashed border-slate-200 bg-slate-50 p-5 text-center text-sm text-slate-500">
                    {{ t('merchants.activities.empty') }}
                </div>

                <ul v-else class="mt-4 grid gap-2 sm:grid-cols-2">
                    <li
                        v-for="activity in merchant.activities"
                        :key="activity.id"
                        class="flex items-start justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3"
                    >
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ activity.code }}</p>
                            <p class="text-sm font-semibold text-slate-950">{{ activity.name_en }}</p>
                            <p dir="rtl" class="text-xs text-slate-500">{{ activity.name_ar }}</p>
                        </div>
                        <StatusPill v-if="activity.is_primary" :label="t('merchants.activities.primary')" tone="green" />
                    </li>
                </ul>
            </section>

            <!-- =========================================================
                 PORTAL USERS TAB (blueprint §4.5)
                 Per-merchant invitation + lifecycle management of
                 merchant portal accounts.

                 - Invite button is HARD-DISABLED when the merchant
                   has no branches or no devices (server-side gate +
                   client-side gate). The disabled state shows a
                   tooltip-style hint via `title`.
                 - Per row: status pill, last login, scope chip
                   ("All branches" or "N branches"), and either
                   "Resend invite" (when setup is still pending) OR
                   "Suspend" / "Reactivate" (after setup).
                 ======================================================== -->
            <!-- ====================== BRANCHES TAB ===================== -->
            <!-- Slim read-only list of every branch belonging to this
                 merchant. Tapping a row opens the Branch Show page
                 where the admin can edit the geofence/operating hours
                 etc. Header CTA links to the Create flow with the
                 merchant pre-selected so onboarding officers don't
                 lose context. -->
            <section v-if="activeTab === 'branches'" class="space-y-6">
                <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                                {{ t('merchants.branches.title') }}
                            </h3>
                            <p class="mt-1 text-sm text-slate-600">{{ t('merchants.branches.subtitle') }}</p>
                        </div>
                        <RouterLink
                            v-if="merchant && can(PlatformPermission.BranchesCreate)"
                            :to="`/admin/branches/new?company_uuid=${merchant.uuid}`"
                            class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:-translate-y-0.5 hover:bg-slate-800"
                        >
                            {{ t('merchants.branches.new') }}
                        </RouterLink>
                    </div>

                    <div v-if="branchesError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                        {{ branchesError }}
                    </div>

                    <!-- Three states: loading skeleton, empty, table.
                         Mirrors the Devices Index pattern. -->
                    <div v-if="branchesLoading" class="mt-6 text-sm font-medium text-slate-500">
                        {{ t('common.loading') }}
                    </div>

                    <div v-else-if="branchesList.length === 0" class="mt-6 text-sm text-slate-500">
                        {{ t('merchants.branches.empty') }}
                    </div>

                    <div v-else class="mt-6 overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.branches.table.name') }}</th>
                                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.branches.table.code') }}</th>
                                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.branches.table.manager') }}</th>
                                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.branches.table.status') }}</th>
                                    <th class="px-4 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('common.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                <tr v-for="branch in branchesList" :key="branch.id" class="transition hover:bg-slate-50">
                                    <td class="px-4 py-3">
                                        <RouterLink
                                            :to="`/admin/branches/${branch.uuid}`"
                                            class="text-sm font-semibold text-slate-950 hover:text-teal-700"
                                        >
                                            {{ locale === 'ar' && branch.name_ar ? branch.name_ar : branch.name }}
                                        </RouterLink>
                                    </td>
                                    <td class="px-4 py-3 text-sm font-mono text-slate-700">{{ branch.code ?? '—' }}</td>
                                    <td class="px-4 py-3 text-sm text-slate-700">{{ branch.manager_name ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <StatusPill
                                            :label="branch.status ?? '—'"
                                            :tone="branch.status === 'active' ? 'green' : 'slate'"
                                        />
                                    </td>
                                    <td class="px-4 py-3 text-end">
                                        <div class="inline-flex items-center gap-2">
                                            <RouterLink
                                                v-if="can(PlatformPermission.BranchesUpdate)"
                                                :to="`/admin/branches/${branch.uuid}`"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                                            >
                                                <Pencil class="size-3.5" />
                                                {{ t('common.edit') }}
                                            </RouterLink>
                                            <button
                                                v-if="can(PlatformPermission.BranchesDelete)"
                                                type="button"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-50"
                                                @click="openBranchDelete(branch)"
                                            >
                                                <Trash2 class="size-3.5" />
                                                {{ t('common.delete') }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination footer — shown only when there's
                         more than one page. Same shape as the
                         standalone Branches/Index page. -->
                    <div
                        v-if="branchesMeta && branchesMeta.last_page > 1"
                        class="mt-4 flex items-center justify-between gap-3 border-t border-slate-200 pt-3 text-sm text-slate-600"
                    >
                        <span>{{ t('common.pagination_summary', { from: branchesMeta.from ?? 0, to: branchesMeta.to ?? 0, total: branchesMeta.total }) }}</span>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                class="rounded-lg border border-slate-200 bg-white px-3 py-2 font-semibold text-slate-700 disabled:opacity-50"
                                :disabled="branchesPage <= 1"
                                @click="branchesPage--; fetchBranchesForTab()"
                            >
                                {{ t('common.previous') }}
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border border-slate-200 bg-white px-3 py-2 font-semibold text-slate-700 disabled:opacity-50"
                                :disabled="branchesPage >= branchesMeta.last_page"
                                @click="branchesPage++; fetchBranchesForTab()"
                            >
                                {{ t('common.next') }}
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- ======================= DEVICES TAB ===================== -->
            <!-- Same shape as Branches: read-only list scoped to this
                 merchant, each row links to the Device Show page
                 where the admin can (re)assign, unassign, etc. The
                 Register button deep-links to the new-device flow;
                 we don't pre-fill the merchant there because the
                 register step is decoupled from assignment by design
                 (blueprint §4.4) — the device first lives
                 unassigned, then the admin assigns it to a branch. -->
            <section v-if="activeTab === 'devices'" class="space-y-6">
                <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                                {{ t('merchants.devices.title') }}
                            </h3>
                            <p class="mt-1 text-sm text-slate-600">{{ t('merchants.devices.subtitle') }}</p>
                        </div>
                        <RouterLink
                            v-if="can(PlatformPermission.DevicesRegister)"
                            to="/admin/devices/new"
                            class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:-translate-y-0.5 hover:bg-slate-800"
                        >
                            {{ t('merchants.devices.new') }}
                        </RouterLink>
                    </div>

                    <div v-if="devicesError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                        {{ devicesError }}
                    </div>

                    <div v-if="devicesLoading" class="mt-6 text-sm font-medium text-slate-500">
                        {{ t('common.loading') }}
                    </div>

                    <div v-else-if="devicesList.length === 0" class="mt-6 text-sm text-slate-500">
                        {{ t('merchants.devices.empty') }}
                    </div>

                    <div v-else class="mt-6 overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.devices.table.label') }}</th>
                                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.devices.table.kiosk_id') }}</th>
                                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.devices.table.branch') }}</th>
                                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.devices.table.bank') }}</th>
                                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.devices.table.status') }}</th>
                                    <th class="px-4 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('common.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                <tr v-for="device in devicesList" :key="device.id" class="transition hover:bg-slate-50">
                                    <td class="px-4 py-3">
                                        <RouterLink
                                            :to="`/admin/devices/${device.uuid}`"
                                            class="text-sm font-semibold text-slate-950 hover:text-teal-700"
                                        >
                                            {{ device.label ?? device.name ?? device.serial_number }}
                                        </RouterLink>
                                        <span class="mt-0.5 block text-xs text-slate-500">{{ device.serial_number }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-sm font-mono text-slate-700">{{ device.kiosk_id ?? '—' }}</td>
                                    <td class="px-4 py-3 text-sm text-slate-700">{{ device.branch?.name ?? '—' }}</td>
                                    <td class="px-4 py-3 text-sm text-slate-700">
                                        {{ device.bank?.short_name ?? device.bank?.name ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <StatusPill
                                            :label="deviceStatusLabel(device.status)"
                                            :tone="deviceStatusTone(device.status)"
                                        />
                                    </td>
                                    <td class="px-4 py-3 text-end">
                                        <div class="inline-flex items-center gap-2">
                                            <RouterLink
                                                v-if="can(PlatformPermission.DevicesView)"
                                                :to="`/admin/devices/${device.uuid}`"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                                            >
                                                <Pencil class="size-3.5" />
                                                {{ t('common.view') }}
                                            </RouterLink>
                                            <button
                                                v-if="can(PlatformPermission.DevicesDecommission)"
                                                type="button"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-50"
                                                @click="openDeviceDecommission(device)"
                                            >
                                                <Power class="size-3.5" />
                                                {{ t('devices.decommission') }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination footer for the Devices tab. -->
                    <div
                        v-if="devicesMeta && devicesMeta.last_page > 1"
                        class="mt-4 flex items-center justify-between gap-3 border-t border-slate-200 pt-3 text-sm text-slate-600"
                    >
                        <span>{{ t('common.pagination_summary', { from: devicesMeta.from ?? 0, to: devicesMeta.to ?? 0, total: devicesMeta.total }) }}</span>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                class="rounded-lg border border-slate-200 bg-white px-3 py-2 font-semibold text-slate-700 disabled:opacity-50"
                                :disabled="devicesPage <= 1"
                                @click="devicesPage--; fetchDevicesForTab()"
                            >
                                {{ t('common.previous') }}
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border border-slate-200 bg-white px-3 py-2 font-semibold text-slate-700 disabled:opacity-50"
                                :disabled="devicesPage >= devicesMeta.last_page"
                                @click="devicesPage++; fetchDevicesForTab()"
                            >
                                {{ t('common.next') }}
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Sprint-X destructive-action confirm dialogs. Mounted
                 at the page root (not inside the tab sections) so a
                 navigation back-and-forth between tabs doesn't tear
                 down the dialog mid-keystroke. -->
            <ConfirmDialog
                v-if="merchantDeleteOpen && merchant"
                :title="t('merchants.delete.title')"
                :message="t('merchants.delete.message', { name: merchant.name })"
                :confirm-label="t('common.delete')"
                :loading="merchantDeleting"
                :error="merchantDeleteError"
                @confirm="confirmMerchantDelete"
                @cancel="merchantDeleteOpen = false"
            />

            <ConfirmDialog
                v-if="branchDeleteTarget"
                :title="t('branches.delete.title')"
                :message="t('branches.delete.message', { name: branchDeleteTarget.name })"
                :confirm-label="t('common.delete')"
                :loading="branchDeleting"
                :error="branchDeleteError"
                @confirm="confirmBranchDelete"
                @cancel="branchDeleteTarget = null"
            />
            <ConfirmDialog
                v-if="deviceDecommTarget"
                tone="danger"
                :title="t('devices.decommission_dialog.title')"
                :message="t('devices.decommission_dialog.message', { label: deviceDecommTarget.label ?? deviceDecommTarget.name ?? deviceDecommTarget.serial_number })"
                :confirm-label="t('devices.decommission')"
                :loading="deviceDecommissioning"
                :error="deviceDecommError"
                @confirm="confirmDeviceDecommission"
                @cancel="deviceDecommTarget = null"
            />

            <section v-if="activeTab === 'portal_users'" class="space-y-6">
                <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                                {{ t('merchants.portal_users.title') }}
                            </h3>
                            <p class="mt-1 text-sm text-slate-600">{{ t('merchants.portal_users.subtitle') }}</p>
                        </div>
                        <button
                            v-if="can(PlatformPermission.MerchantUsersInvite)"
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:shadow-none"
                            :disabled="!canInvite"
                            :title="!canInvite ? t('merchants.portal_users.create.disabled_reason') : ''"
                            @click="openCreate"
                        >
                            <UserPlus class="size-4" />
                            {{ t('merchants.portal_users.create_button') }}
                        </button>
                    </div>

                    <!-- Gating banner — explains WHY the Create
                         button is disabled when prerequisites are
                         missing (no branches / no devices). -->
                    <div
                        v-if="!canInvite"
                        class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900"
                    >
                        {{ t('merchants.portal_users.create.disabled_reason') }}
                    </div>

                    <div v-if="portalError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                        {{ portalError }}
                    </div>

                    <div v-if="portalLoading" class="mt-6 flex items-center gap-3 text-sm text-slate-500">
                        <Loader2 class="size-4 animate-spin" />
                        {{ t('common.loading') }}
                    </div>

                    <div v-else-if="portalUsers.length === 0" class="mt-6 flex flex-col items-center gap-2 rounded-lg border border-dashed border-slate-200 px-4 py-10 text-center text-sm text-slate-500">
                        <Users class="size-8 text-slate-300" />
                        <p>{{ t('merchants.portal_users.empty') }}</p>
                    </div>

                    <div v-else class="mt-6 overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.portal_users.table.name') }}</th>
                                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.portal_users.table.email') }}</th>
                                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.portal_users.table.scope') }}</th>
                                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.portal_users.table.last_login') }}</th>
                                    <th class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.portal_users.table.status') }}</th>
                                    <th class="px-4 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.portal_users.table.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                <tr v-for="user in portalUsers" :key="user.id">
                                    <td class="px-4 py-3">
                                        <p class="text-sm font-semibold text-slate-950">{{ user.name }}</p>
                                        <p v-if="user.phone" class="text-xs text-slate-500">{{ user.phone }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-sm font-medium text-slate-700">{{ user.email }}</td>
                                    <td class="px-4 py-3 text-xs">
                                        <span
                                            v-if="user.branch_scope === null"
                                            class="rounded-full bg-slate-100 px-2 py-1 font-semibold text-slate-700"
                                        >
                                            {{ t('merchants.portal_users.scope.all') }}
                                        </span>
                                        <span
                                            v-else
                                            class="rounded-full bg-sky-100 px-2 py-1 font-semibold text-sky-700"
                                        >
                                            {{ t('merchants.portal_users.scope.restricted', { count: user.branch_scope.length }) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-slate-500">
                                        {{ user.last_login_at ?? t('merchants.portal_users.never_logged_in') }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <StatusPill
                                            :label="t(`merchants.portal_users.status.${user.status ?? 'inactive'}`)"
                                            :tone="portalStatusTone(user.status)"
                                        />
                                        <p v-if="user.setup_pending" class="mt-1 text-[10px] font-semibold uppercase tracking-wider text-amber-700">
                                            {{ t('merchants.portal_users.pending_setup') }}
                                        </p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center justify-end gap-2">
                                            <!-- Reset password — generates a fresh password
                                                 server-side and shows it ONCE in the
                                                 password modal. Replaces the obsolete
                                                 "resend invite" button. -->
                                            <button
                                                v-if="can(PlatformPermission.MerchantUsersInvite)"
                                                type="button"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                                                :disabled="rowBusy[user.id]"
                                                @click="onResetPassword(user)"
                                            >
                                                <RotateCw class="size-3.5" :class="{ 'animate-spin': rowBusy[user.id] }" />
                                                {{ t('merchants.portal_users.actions.reset_password') }}
                                            </button>

                                            <!-- Suspend / Reactivate. Different label +
                                                 colour depending on current status. -->
                                            <button
                                                v-if="can(PlatformPermission.MerchantUsersRevoke)"
                                                type="button"
                                                class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold disabled:opacity-60"
                                                :class="user.status === 'suspended'
                                                    ? 'border border-emerald-200 bg-white text-emerald-700 hover:bg-emerald-50'
                                                    : 'border border-rose-200 bg-white text-rose-700 hover:bg-rose-50'"
                                                :disabled="rowBusy[user.id]"
                                                @click="toggleStatus(user)"
                                            >
                                                <PlayCircle v-if="user.status === 'suspended'" class="size-3.5" />
                                                <Ban v-else class="size-3.5" />
                                                {{ user.status === 'suspended' ? t('merchants.portal_users.actions.reactivate') : t('merchants.portal_users.actions.suspend') }}
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- CREATE USER MODAL ------------------------------------- -->
                <!-- Three fields, no branch scope picker — initial
                     user is unscoped super admin by definition. -->
                <div
                    v-if="createOpen"
                    class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 backdrop-blur-sm px-4"
                    @click.self="closeCreate"
                >
                    <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
                        <div class="flex items-center justify-between">
                            <h2 class="text-lg font-semibold text-slate-950">{{ t('merchants.portal_users.create.title') }}</h2>
                            <button type="button" class="rounded-lg p-1 text-slate-500 hover:bg-slate-100" @click="closeCreate">
                                <X class="size-5" />
                            </button>
                        </div>
                        <p class="mt-2 text-sm text-slate-600">{{ t('merchants.portal_users.create.subtitle') }}</p>

                        <div v-if="createError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                            {{ createError }}
                        </div>

                        <form class="mt-6 space-y-4" @submit.prevent="submitCreate">
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">{{ t('merchants.portal_users.create.name') }} *</span>
                                <input v-model="createForm.name" type="text" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <p v-if="createFieldErrors.name" class="mt-1 text-xs text-rose-600">{{ createFieldErrors.name[0] }}</p>
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">{{ t('merchants.portal_users.create.email') }} *</span>
                                <input v-model="createForm.email" type="email" autocomplete="off" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <p v-if="createFieldErrors.email" class="mt-1 text-xs text-rose-600">{{ createFieldErrors.email[0] }}</p>
                            </label>
                            <label class="block">
                                <span class="text-sm font-medium text-slate-700">{{ t('merchants.portal_users.create.phone') }}</span>
                                <input v-model="createForm.phone" type="tel" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                                <p v-if="createFieldErrors.phone" class="mt-1 text-xs text-rose-600">{{ createFieldErrors.phone[0] }}</p>
                            </label>

                            <div class="flex items-center justify-end gap-3 pt-2">
                                <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="closeCreate">
                                    {{ t('common.cancel') }}
                                </button>
                                <button
                                    type="submit"
                                    :disabled="creating"
                                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800 disabled:cursor-wait disabled:opacity-70"
                                >
                                    <UserPlus class="size-4" />
                                    {{ creating ? t('merchants.portal_users.create.submitting') : t('merchants.portal_users.create.submit') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- ONE-SHOT PASSWORD MODAL ----------------------- -->
                <div
                    v-if="passwordModalOpen && passwordModalUser"
                    class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 backdrop-blur-sm px-4"
                >
                    <div class="w-full max-w-lg rounded-xl bg-white shadow-xl">
                        <div class="border-b border-slate-200 px-6 py-5">
                            <h2 class="text-lg font-semibold text-slate-950">{{ t('merchants.portal_users.password_modal.title') }}</h2>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ t('merchants.portal_users.password_modal.subtitle', { name: passwordModalUser.name, email: passwordModalUser.email }) }}
                            </p>
                        </div>

                        <div class="space-y-4 px-6 py-6">
                            <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800">
                                {{ t('merchants.portal_users.password_modal.one_shot_warning') }}
                            </div>

                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.portal_users.password_modal.password_label') }}</span>
                                <div class="mt-2 flex gap-2">
                                    <input
                                        id="portal-user-password-out"
                                        :value="passwordModalSecret"
                                        readonly
                                        class="flex-1 rounded-lg border border-slate-200 px-3 py-2.5 text-sm font-mono tracking-wider text-slate-950 focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                                    >
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-2.5 text-sm font-semibold transition"
                                        :class="passwordCopied ? 'border-teal-300 bg-teal-50 text-teal-700' : 'text-slate-700 hover:bg-slate-50'"
                                        @click="copyPortalPassword"
                                    >
                                        {{ passwordCopied ? t('merchants.portal_users.password_modal.copied') : t('merchants.portal_users.password_modal.copy') }}
                                    </button>
                                </div>
                            </label>
                        </div>

                        <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-6 py-4">
                            <button type="button" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800" @click="closePasswordModal">
                                {{ t('merchants.portal_users.password_modal.done') }}
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <section v-if="activeTab === 'history'" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ t('merchants.history.title') }}</h3>
                    <History class="size-5 text-slate-400" />
                </div>

                <ol v-if="merchant.status_history && merchant.status_history.length > 0" class="mt-6 space-y-4">
                    <li v-for="entry in merchant.status_history" :key="entry.id" class="flex gap-3">
                        <span class="mt-1 size-2 shrink-0 rounded-full bg-teal-500" />
                        <div>
                            <p class="text-sm font-semibold text-slate-950">
                                {{ entry.from_status ? statusLabel(entry.from_status) + ' → ' : '' }}{{ statusLabel(entry.to_status) }}
                            </p>
                            <p v-if="entry.reason" class="text-sm text-slate-600">{{ entry.reason }}</p>
                            <p class="mt-1 text-xs text-slate-400">{{ entry.changed_at }}</p>
                        </div>
                    </li>
                </ol>
                <p v-else class="mt-4 text-sm text-slate-500">{{ t('merchants.history.empty') }}</p>
            </section>

            <div>
                <RouterLink to="/admin/merchants" class="text-sm font-semibold text-teal-700 hover:text-teal-900">
                    ← {{ t('merchants.back_to_list') }}
                </RouterLink>
            </div>
        </section>
    </AdminLayout>
</template>
