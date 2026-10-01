<script setup lang="ts">
/**
 * Platform Team — admin user management page.
 *
 * Routed at /admin/team. Sidebar nav (gated by PlatformUsersView)
 * already points here.
 *
 * What lives here:
 *   - Table of platform admins (name, email, role chip, status pill,
 *     last login, actions column)
 *   - "Invite admin" button → modal with name / email / role
 *   - After-invite: the one-time "Copy set-password link" dialog
 *     (LAUNCH-P1 P1-8 — no password is generated or shown).
 *   - Per-row "Resend set-password link" / "Send reset link"
 *   - Per-row "Reset two-step login" (Super Admin only, with a
 *     confirmation and a written reason — LAUNCH-P1 P1-15)
 *   - Per-row Edit (change role / name / phone) modal
 *   - Per-row Suspend / Reactivate buttons (server enforces "can't
 *     suspend yourself")
 */

import { KeyRound, Pencil, Plus, ShieldCheck, ShieldOff, ShieldX, Users } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import ForceUserLogout from '@/Components/Admin/ForceUserLogout.vue';
import BaseModal from '@/Components/BaseModal.vue';
import ConfirmDialog from '@/Components/Admin/ConfirmDialog.vue';
import SetPasswordLinkDialog from '@/Components/Admin/SetPasswordLinkDialog.vue';
import StatusPill, { type StatusTone } from '@/Components/Admin/StatusPill.vue';
import { usePermissions } from '@/composables/usePermissions';
import { ApiError } from '@/lib/api';
import {
    invitePlatformUser,
    listPlatformTeam,
    reactivatePlatformUser,
    resetPlatformUserTwoFactor,
    sendPlatformUserPasswordLink,
    suspendPlatformUser,
    updatePlatformUser,
    type InvitePlatformUserPayload,
    type PlatformRoleName,
    type PlatformUser,
    type PlatformUserStatus,
    type UpdatePlatformUserPayload,
} from '@/lib/api/platformTeam';
import type { SetPasswordLink } from '@/lib/api/setPasswordLink';
import type { PaginationMeta } from '@/lib/api/merchants';
import { PlatformPermission, PlatformRole } from '@/lib/permissions';
import { authState } from '@/stores/auth';

const { t, locale } = useI18n();
const { can } = usePermissions();

// ---- Table state --------------------------------------------------
const users = ref<PlatformUser[]>([]);
const meta = ref<PaginationMeta | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

const search = ref('');
const statusFilter = ref<PlatformUserStatus | ''>('');
const page = ref(1);

// Per-row spinner flag — a click on Suspend on row 7 only spins
// row 7, not the whole table.
const rowBusy = ref<Record<number, boolean>>({});

// ---- Invite modal -------------------------------------------------
const inviteOpen = ref(false);
const inviting = ref(false);
const inviteFieldErrors = ref<Record<string, string[]>>({});
const inviteError = ref<string | null>(null);
const inviteForm = reactive<InvitePlatformUserPayload>({
    name: '',
    email: '',
    phone: '',
    role: 'support',
});

// ---- "Copy set-password link" dialog (P1-8, one-shot) --------------
const linkDialog = ref<{ link: SetPasswordLink; user: PlatformUser } | null>(null);
// A reset link signs the admin out everywhere: ask first.
const resetLinkTarget = ref<PlatformUser | null>(null);

// ---- Reset two-step login (P1-15, Super Admin only) ----------------
const twoFactorTarget = ref<PlatformUser | null>(null);
const twoFactorReason = ref('');
const twoFactorBusy = ref(false);
const twoFactorError = ref<string | null>(null);
const isSuperAdmin = computed(() => (authState.user?.roles ?? []).includes(PlatformRole.SuperAdmin));

// ---- Edit-role modal ----------------------------------------------
const editOpen = ref(false);
const editing = ref(false);
const editFieldErrors = ref<Record<string, string[]>>({});
const editError = ref<string | null>(null);
const editTarget = ref<PlatformUser | null>(null);
const editForm = reactive<UpdatePlatformUserPayload>({
    name: '',
    phone: '',
    role: 'support',
});

// ---- Catalogues ---------------------------------------------------

const roleOptions: { value: PlatformRoleName; key: string }[] = [
    { value: PlatformRole.SuperAdmin as PlatformRoleName, key: 'super_admin' },
    { value: PlatformRole.OnboardingOfficer as PlatformRoleName, key: 'onboarding_officer' },
    { value: PlatformRole.DeviceOperations as PlatformRoleName, key: 'device_operations' },
    { value: PlatformRole.Support as PlatformRoleName, key: 'support' },
    { value: PlatformRole.FinanceViewer as PlatformRoleName, key: 'finance_viewer' },
];

const statusOptions: { value: PlatformUserStatus; tone: StatusTone }[] = [
    { value: 'active', tone: 'green' },
    { value: 'inactive', tone: 'slate' },
    { value: 'suspended', tone: 'rose' },
];

function roleLabel(role: PlatformRoleName | null | undefined): string {
    if (!role) {
        return '—';
    }
    const opt = roleOptions.find((r) => r.value === role);
    return opt ? t(`team.roles.${opt.key}`) : role;
}

function statusLabel(status: PlatformUserStatus | null | undefined): string {
    if (!status) {
        return '—';
    }
    const key = `team.statuses.${status}`;
    const translated = t(key);
    return translated === key ? status : translated;
}

function statusTone(status: PlatformUserStatus | null | undefined): StatusTone {
    return statusOptions.find((s) => s.value === status)?.tone ?? 'slate';
}

function formatTimestamp(iso: string | null): string {
    if (!iso) {
        return '—';
    }
    try {
        const date = new Date(iso);
        if (Number.isNaN(date.getTime())) {
            return iso;
        }
        return date.toLocaleString(locale.value === 'ar' ? 'ar-OM' : 'en-GB', {
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit',
        });
    } catch {
        return iso;
    }
}

const currentUserId = computed(() => authState.user?.id);

function isSelf(row: PlatformUser): boolean {
    return currentUserId.value !== undefined && Number(currentUserId.value) === row.id;
}

// ---- Fetcher ------------------------------------------------------
async function fetchPage(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const response = await listPlatformTeam({
            page: page.value,
            search: search.value || undefined,
            status: statusFilter.value || undefined,
        });
        users.value = response.data;
        meta.value = response.meta;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Failed to load platform team';
    } finally {
        loading.value = false;
    }
}

// Debounce filter changes by 250ms so typing doesn't fire one
// request per keystroke. Same pattern as Devices/Index.vue.
let debounceTimer: number | null = null;
watch([search, statusFilter], () => {
    if (debounceTimer) {
        window.clearTimeout(debounceTimer);
    }
    debounceTimer = window.setTimeout(() => {
        page.value = 1;
        void fetchPage();
    }, 250);
});

onMounted(() => {
    void fetchPage();
});

// ---- Invite flow --------------------------------------------------

function openInvite(): void {
    inviteFieldErrors.value = {};
    inviteError.value = null;
    inviteForm.name = '';
    inviteForm.email = '';
    inviteForm.phone = '';
    inviteForm.role = 'support';
    inviteOpen.value = true;
}

async function submitInvite(): Promise<void> {
    inviting.value = true;
    inviteFieldErrors.value = {};
    inviteError.value = null;
    try {
        const response = await invitePlatformUser({
            name: inviteForm.name,
            email: inviteForm.email,
            phone: inviteForm.phone || null,
            role: inviteForm.role,
        });
        // Close the invite modal and show the one-time link dialog.
        inviteOpen.value = false;
        linkDialog.value = { link: response.set_password_link, user: response.data };
        void fetchPage();
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            inviteFieldErrors.value = err.payload.errors;
            inviteError.value = t('team.invite.validation_summary');
        } else {
            inviteError.value = err instanceof Error ? err.message : 'Invite failed';
        }
    } finally {
        inviting.value = false;
    }
}

function closeLinkDialog(): void {
    linkDialog.value = null;
    void fetchPage();
}

/**
 * "Resend set-password link" (never set a password: no confirmation) or
 * "Send reset link" (confirmed first: it ends that admin's sessions).
 */
function onPasswordLink(row: PlatformUser): void {
    if (row.password_set === false) {
        void sendPasswordLink(row);
        return;
    }
    resetLinkTarget.value = row;
}

async function sendPasswordLink(row: PlatformUser): Promise<void> {
    rowBusy.value[row.id] = true;
    try {
        const response = await sendPlatformUserPasswordLink(row.id);
        resetLinkTarget.value = null;
        linkDialog.value = { link: response.set_password_link, user: response.data };
        void fetchPage();
    } catch (err) {
        resetLinkTarget.value = null;
        error.value = err instanceof Error ? err.message : 'Action failed';
    } finally {
        rowBusy.value[row.id] = false;
    }
}

function openTwoFactorReset(row: PlatformUser): void {
    twoFactorTarget.value = row;
    twoFactorReason.value = '';
    twoFactorError.value = null;
}

async function submitTwoFactorReset(): Promise<void> {
    if (!twoFactorTarget.value) {
        return;
    }
    if (twoFactorReason.value.trim().length < 5) {
        twoFactorError.value = t('team.two_factor_reset.reason_required');
        return;
    }
    twoFactorBusy.value = true;
    twoFactorError.value = null;
    try {
        await resetPlatformUserTwoFactor(twoFactorTarget.value.id, twoFactorReason.value.trim());
        twoFactorTarget.value = null;
        await fetchPage();
    } catch (err) {
        twoFactorError.value = err instanceof ApiError
            ? (err.firstValidationMessage() ?? (err.payload && typeof err.payload === 'object' && 'message' in err.payload
                ? String((err.payload as { message?: unknown }).message)
                : err.message))
            : err instanceof Error ? err.message : 'Action failed';
    } finally {
        twoFactorBusy.value = false;
    }
}

// ---- Edit flow ----------------------------------------------------

function openEdit(row: PlatformUser): void {
    editTarget.value = row;
    editForm.name = row.name;
    editForm.phone = row.phone ?? '';
    editForm.role = row.role ?? 'support';
    editFieldErrors.value = {};
    editError.value = null;
    editOpen.value = true;
}

async function submitEdit(): Promise<void> {
    if (!editTarget.value) {
        return;
    }
    editing.value = true;
    editFieldErrors.value = {};
    editError.value = null;
    try {
        await updatePlatformUser(editTarget.value.id, {
            name: editForm.name,
            phone: editForm.phone || null,
            role: editForm.role,
        });
        editOpen.value = false;
        await fetchPage();
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            editFieldErrors.value = err.payload.errors;
            editError.value = t('team.invite.validation_summary');
        } else {
            editError.value = err instanceof Error ? err.message : 'Update failed';
        }
    } finally {
        editing.value = false;
    }
}

// ---- Suspend / Reactivate ----------------------------------------

async function toggleSuspension(row: PlatformUser): Promise<void> {
    rowBusy.value[row.id] = true;
    try {
        if (row.status === 'suspended') {
            await reactivatePlatformUser(row.id);
        } else {
            await suspendPlatformUser(row.id);
        }
        await fetchPage();
    } catch (err) {
        // 422 from the "cannot suspend self" guard surfaces here.
        error.value = err instanceof ApiError && err.payload && typeof err.payload === 'object' && 'message' in err.payload
            ? String((err.payload as { message?: unknown }).message ?? 'Action failed')
            : err instanceof Error ? err.message : 'Action failed';
    } finally {
        rowBusy.value[row.id] = false;
    }
}
</script>

<template>
    <AdminLayout>
        <section class="space-y-6">
            <!-- Header strip. Invite button gated by PlatformUsersInvite. -->
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-teal-700">
                        {{ t('team.section_label') }}
                    </p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-950">
                        {{ t('team.title') }}
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                        {{ t('team.subtitle') }}
                    </p>
                </div>

                <button
                    v-if="can(PlatformPermission.PlatformUsersInvite)"
                    type="button"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-slate-950 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800 hover:shadow-xl"
                    @click="openInvite"
                >
                    <Plus class="size-4" />
                    {{ t('team.invite.button') }}
                </button>
            </div>

            <!-- Filter strip. -->
            <div class="grid gap-3 sm:grid-cols-[1fr_auto]">
                <input
                    v-model="search"
                    type="search"
                    :placeholder="t('team.search_placeholder')"
                    class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-700 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                >
                <select
                    v-model="statusFilter"
                    class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                >
                    <option value="">{{ t('team.filter_all_statuses') }}</option>
                    <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">
                        {{ statusLabel(opt.value) }}
                    </option>
                </select>
            </div>

            <div v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                {{ error }}
            </div>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div v-if="loading" class="p-10 text-center text-sm font-medium text-slate-500">
                    {{ t('common.loading') }}
                </div>

                <div v-else-if="users.length === 0" class="flex flex-col items-center gap-3 p-12 text-center text-slate-500">
                    <Users class="size-10 text-slate-300" />
                    <p class="text-sm font-semibold">{{ t('team.empty_state') }}</p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('team.table.name') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('team.table.role') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('team.table.status') }}</th>
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('team.table.last_login') }}</th>
                                <th class="px-5 py-3 text-end text-xs font-semibold uppercase tracking-wide text-slate-500">{{ t('team.table.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <tr v-for="row in users" :key="row.id" class="transition hover:bg-slate-50">
                                <td class="px-5 py-4">
                                    <span class="block text-sm font-semibold text-slate-950">{{ row.name }}</span>
                                    <span class="block text-xs text-slate-500">{{ row.email }}</span>
                                </td>
                                <td class="px-5 py-4 text-sm font-medium text-slate-700">{{ roleLabel(row.role) }}</td>
                                <td class="px-5 py-4">
                                    <StatusPill :label="statusLabel(row.status)" :tone="statusTone(row.status)" />
                                    <p v-if="row.password_set === false" class="mt-1 text-[10px] font-semibold uppercase tracking-wider text-amber-700">
                                        {{ t('team.pending_password') }}
                                    </p>
                                    <p v-else-if="row.two_factor_enabled === false" class="mt-1 text-[10px] font-semibold uppercase tracking-wider text-amber-700">
                                        {{ t('team.pending_two_factor') }}
                                    </p>
                                </td>
                                <td class="px-5 py-4 text-xs font-mono text-slate-500">{{ formatTimestamp(row.last_login_at) }}</td>
                                <td class="px-5 py-4 text-end">
                                    <div class="inline-flex flex-wrap items-center justify-end gap-2">
                                        <ForceUserLogout :user-id="row.id" :user-name="row.name" />
                                        <!-- P1-8: set-password links, never passwords. -->
                                        <button
                                            v-if="can(PlatformPermission.PlatformUsersInvite) && !isSelf(row)"
                                            type="button"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60"
                                            :disabled="rowBusy[row.id]"
                                            @click="onPasswordLink(row)"
                                        >
                                            <KeyRound class="size-3.5" />
                                            {{ row.password_set === false ? t('team.actions.resend_link') : t('team.actions.send_reset_link') }}
                                        </button>
                                        <!-- P1-15: Super Admin resets a lost authenticator. -->
                                        <button
                                            v-if="isSuperAdmin && !isSelf(row) && row.two_factor_enabled"
                                            type="button"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-amber-200 px-3 py-1.5 text-xs font-semibold text-amber-800 transition hover:bg-amber-50"
                                            data-testid="reset-two-factor"
                                            @click="openTwoFactorReset(row)"
                                        >
                                            <ShieldX class="size-3.5" />
                                            {{ t('team.actions.reset_two_factor') }}
                                        </button>
                                        <button
                                            v-if="can(PlatformPermission.PlatformUsersUpdateRoles)"
                                            type="button"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                                            @click="openEdit(row)"
                                        >
                                            <Pencil class="size-3.5" />
                                            {{ t('team.actions.edit') }}
                                        </button>
                                        <button
                                            v-if="can(PlatformPermission.PlatformUsersSuspend) && !isSelf(row)"
                                            type="button"
                                            class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold transition disabled:cursor-wait disabled:opacity-60"
                                            :class="row.status === 'suspended'
                                                ? 'border-teal-200 text-teal-700 hover:bg-teal-50'
                                                : 'border-rose-200 text-rose-700 hover:bg-rose-50'"
                                            :disabled="rowBusy[row.id]"
                                            @click="toggleSuspension(row)"
                                        >
                                            <ShieldCheck v-if="row.status === 'suspended'" class="size-3.5" />
                                            <ShieldOff v-else class="size-3.5" />
                                            {{ row.status === 'suspended' ? t('team.actions.reactivate') : t('team.actions.suspend') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="meta && meta.last_page > 1"
                    class="flex items-center justify-between gap-3 border-t border-slate-200 bg-slate-50/60 px-5 py-3 text-sm text-slate-600"
                >
                    <span>{{ t('common.pagination_summary', { from: meta.from ?? 0, to: meta.to ?? 0, total: meta.total }) }}</span>
                    <div class="flex gap-2">
                        <button
                            type="button"
                            class="rounded-lg border border-slate-200 bg-white px-3 py-2 font-semibold text-slate-700 disabled:opacity-50"
                            :disabled="page <= 1"
                            @click="page--; fetchPage()"
                        >
                            {{ t('common.previous') }}
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-slate-200 bg-white px-3 py-2 font-semibold text-slate-700 disabled:opacity-50"
                            :disabled="page >= meta.last_page"
                            @click="page++; fetchPage()"
                        >
                            {{ t('common.next') }}
                        </button>
                    </div>
                </div>
            </section>
        </section>

        <!-- ================= INVITE MODAL ================== -->
        <BaseModal v-if="inviteOpen" size="lg" :loading="inviting" @close="inviteOpen = false">
            <template #header>
                <h2 class="text-lg font-semibold text-slate-950">{{ t('team.invite.title') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ t('team.invite.subtitle') }}</p>
            </template>

            <form id="invite-team-form" class="space-y-4" @submit.prevent="submitInvite">
                <div v-if="inviteError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                    {{ inviteError }}
                </div>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('team.fields.name') }} *</span>
                    <input v-model="inviteForm.name" required type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    <p v-if="inviteFieldErrors.name" class="mt-1 text-xs text-rose-600">{{ inviteFieldErrors.name[0] }}</p>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('team.fields.email') }} *</span>
                    <input v-model="inviteForm.email" required type="email" autocomplete="off" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    <p v-if="inviteFieldErrors.email" class="mt-1 text-xs text-rose-600">{{ inviteFieldErrors.email[0] }}</p>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('team.fields.phone') }}</span>
                    <input v-model="inviteForm.phone" type="tel" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    <p v-if="inviteFieldErrors.phone" class="mt-1 text-xs text-rose-600">{{ inviteFieldErrors.phone[0] }}</p>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('team.fields.role') }} *</span>
                    <select v-model="inviteForm.role" required class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <option v-for="opt in roleOptions" :key="opt.value" :value="opt.value">
                            {{ t(`team.roles.${opt.key}`) }}
                        </option>
                    </select>
                    <p v-if="inviteFieldErrors.role" class="mt-1 text-xs text-rose-600">{{ inviteFieldErrors.role[0] }}</p>
                </label>
            </form>

            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="inviteOpen = false">
                        {{ t('common.cancel') }}
                    </button>
                    <button type="submit" form="invite-team-form" :disabled="inviting" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                        {{ inviting ? t('team.invite.submitting') : t('team.invite.submit') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- ============== "COPY SET-PASSWORD LINK" (P1-8) ============== -->
        <SetPasswordLinkDialog
            v-if="linkDialog"
            :link="linkDialog.link"
            :user-name="linkDialog.user.name"
            :user-email="linkDialog.user.email"
            @close="closeLinkDialog"
        />

        <ConfirmDialog
            v-if="resetLinkTarget"
            :title="t('team.reset_link_confirm.title')"
            :message="t('team.reset_link_confirm.message', { name: resetLinkTarget.name })"
            :confirm-label="t('team.reset_link_confirm.confirm')"
            tone="primary"
            :loading="rowBusy[resetLinkTarget.id]"
            @confirm="sendPasswordLink(resetLinkTarget)"
            @cancel="resetLinkTarget = null"
        />

        <!-- ============== RESET TWO-STEP LOGIN (P1-15) ============== -->
        <BaseModal
            v-if="twoFactorTarget"
            :title="t('team.two_factor_reset.title')"
            size="md"
            :loading="twoFactorBusy"
            @close="twoFactorTarget = null"
        >
            <form id="reset-two-factor-form" class="space-y-4" @submit.prevent="submitTwoFactorReset">
                <p class="text-sm text-slate-700">{{ t('team.two_factor_reset.message', { name: twoFactorTarget.name }) }}</p>
                <div>
                    <label for="reset-two-factor-reason" class="text-sm font-medium text-slate-700">{{ t('team.two_factor_reset.reason') }} *</label>
                    <textarea
                        id="reset-two-factor-reason"
                        v-model="twoFactorReason"
                        rows="3"
                        required
                        minlength="5"
                        class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                        :placeholder="t('team.two_factor_reset.reason_placeholder')"
                    />
                </div>
                <p v-if="twoFactorError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">{{ twoFactorError }}</p>
            </form>
            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="twoFactorTarget = null">
                        {{ t('common.cancel') }}
                    </button>
                    <button type="submit" form="reset-two-factor-form" :disabled="twoFactorBusy" class="rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700 disabled:opacity-60">
                        {{ t('team.two_factor_reset.confirm') }}
                    </button>
                </div>
            </template>
        </BaseModal>

        <!-- ================= EDIT MODAL ================== -->
        <BaseModal v-if="editOpen && editTarget" size="lg" :loading="editing" @close="editOpen = false">
            <template #header>
                <h2 class="text-lg font-semibold text-slate-950">{{ t('team.edit.title') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ editTarget.email }}</p>
            </template>

            <form id="edit-team-form" class="space-y-4" @submit.prevent="submitEdit">
                <div v-if="editError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                    {{ editError }}
                </div>

                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('team.fields.name') }}</span>
                    <input v-model="editForm.name" type="text" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    <p v-if="editFieldErrors.name" class="mt-1 text-xs text-rose-600">{{ editFieldErrors.name[0] }}</p>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('team.fields.phone') }}</span>
                    <input v-model="editForm.phone" type="tel" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                    <p v-if="editFieldErrors.phone" class="mt-1 text-xs text-rose-600">{{ editFieldErrors.phone[0] }}</p>
                </label>
                <label class="block">
                    <span class="text-sm font-medium text-slate-700">{{ t('team.fields.role') }}</span>
                    <select v-model="editForm.role" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100">
                        <option v-for="opt in roleOptions" :key="opt.value" :value="opt.value">
                            {{ t(`team.roles.${opt.key}`) }}
                        </option>
                    </select>
                    <p v-if="editFieldErrors.role" class="mt-1 text-xs text-rose-600">{{ editFieldErrors.role[0] }}</p>
                </label>
            </form>

            <template #footer>
                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" @click="editOpen = false">
                        {{ t('common.cancel') }}
                    </button>
                    <button type="submit" form="edit-team-form" :disabled="editing" class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
                        {{ editing ? t('team.edit.submitting') : t('team.edit.submit') }}
                    </button>
                </div>
            </template>
        </BaseModal>
    </AdminLayout>
</template>
