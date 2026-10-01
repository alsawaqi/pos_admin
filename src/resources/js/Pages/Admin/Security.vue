<script setup lang="ts">
/**
 * Account Security (Phase D8) — the platform admin's self-service
 * security surface. Admin has no profile page yet (the D7 vertical
 * landed merchant-side only), so this minimal page hosts the TOTP
 * 2FA card: enrol (QR + manual secret + confirm code), one-time
 * recovery codes reveal, and step-up disable (password + code or
 * recovery code). Reached from the header user chip.
 *
 * LAUNCH-P1:
 *  - P1-15 two-step login is REQUIRED for admins: an admin without it
 *    lands here (the router and the server hold them) and sees why.
 *  - P1-8 change-password card for the signed-in admin.
 */
import { Copy, KeyRound, Loader2, Mail, ShieldAlert, ShieldCheck, ShieldOff, UserRound } from 'lucide-vue-next';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { ApiError, apiDelete, apiPost } from '@/lib/api';
import { authState, setAuthTwoFactorEnabled, twoFactorSetupRequired } from '@/stores/auth';

const { t } = useI18n();

const twoFactorEnabled = computed(() => authState.user?.two_factor_enabled === true);
const setupRequired = computed(() => twoFactorSetupRequired());

// ---- Change password (P1-8) -----------------------------------------
const passwordForm = reactive({ current_password: '', password: '', password_confirmation: '' });
const passwordBusy = ref(false);
const passwordError = ref<string | null>(null);
const passwordFieldErrors = ref<Record<string, string[]>>({});
const passwordDone = ref(false);

async function changePassword(): Promise<void> {
    passwordError.value = null;
    passwordFieldErrors.value = {};
    passwordDone.value = false;
    if (passwordForm.password !== passwordForm.password_confirmation) {
        passwordFieldErrors.value = { password: [t('security.password.mismatch')] };
        return;
    }
    passwordBusy.value = true;
    try {
        await apiPost('/auth/change-password', { ...passwordForm });
        passwordForm.current_password = '';
        passwordForm.password = '';
        passwordForm.password_confirmation = '';
        passwordDone.value = true;
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            passwordFieldErrors.value = err.payload.errors;
            passwordError.value = err.firstValidationMessage();
        } else {
            passwordError.value = t('security.password.error_generic');
        }
    } finally {
        passwordBusy.value = false;
    }
}

/** 'idle' | 'enrolling' (QR shown) | 'recovery' (codes shown once) */
const tfaStep = ref<'idle' | 'enrolling' | 'recovery'>('idle');
const tfaBusy = ref(false);
const tfaError = ref<string | null>(null);
const tfaSecret = ref('');
const tfaSvg = ref('');
const tfaConfirmCode = ref('');
const tfaRecoveryCodes = ref<string[]>([]);
const tfaCopied = ref(false);

const showDisableForm = ref(false);
const disablePassword = ref('');
const disableCode = ref('');
const disableUseRecovery = ref(false);
const disableRecoveryCode = ref('');
const tfaDisabledNotice = ref(false);

function tfaHandleError(err: unknown): void {
    if (err instanceof ApiError && err.isValidationError()) {
        tfaError.value = err.firstValidationMessage();
    } else {
        tfaError.value = t('security.two_factor.error_generic');
    }
}

async function startEnrolment(): Promise<void> {
    tfaError.value = null;
    tfaDisabledNotice.value = false;
    tfaBusy.value = true;
    try {
        const response = await apiPost<{ secret: string; otpauth_url: string; svg: string }>('/auth/two-factor');
        tfaSecret.value = response.secret;
        tfaSvg.value = response.svg;
        tfaConfirmCode.value = '';
        tfaStep.value = 'enrolling';
    } catch (err) {
        tfaHandleError(err);
    } finally {
        tfaBusy.value = false;
    }
}

async function confirmEnrolment(): Promise<void> {
    tfaError.value = null;
    tfaBusy.value = true;
    try {
        const response = await apiPost<{ recovery_codes: string[] }>('/auth/two-factor/confirm', {
            code: tfaConfirmCode.value,
        });
        tfaRecoveryCodes.value = response.recovery_codes;
        tfaStep.value = 'recovery';
        setAuthTwoFactorEnabled(true);
    } catch (err) {
        tfaHandleError(err);
    } finally {
        tfaBusy.value = false;
    }
}

function cancelEnrolment(): void {
    // Server keeps the unconfirmed secret around; it never gates
    // login and a future "Enable" rotates it anyway.
    tfaStep.value = 'idle';
    tfaError.value = null;
    tfaSecret.value = '';
    tfaSvg.value = '';
}

async function copyRecoveryCodes(): Promise<void> {
    try {
        await navigator.clipboard.writeText(tfaRecoveryCodes.value.join('\n'));
        tfaCopied.value = true;
        setTimeout(() => {
            tfaCopied.value = false;
        }, 2000);
    } catch {
        // Clipboard unavailable (permissions/http) — codes stay visible.
    }
}

function acknowledgeRecoveryCodes(): void {
    tfaStep.value = 'idle';
    tfaRecoveryCodes.value = [];
    tfaSecret.value = '';
    tfaSvg.value = '';
}

async function disableTwoFactor(): Promise<void> {
    tfaError.value = null;
    tfaBusy.value = true;
    try {
        await apiDelete('/auth/two-factor', {
            current_password: disablePassword.value,
            ...(disableUseRecovery.value
                ? { recovery_code: disableRecoveryCode.value }
                : { code: disableCode.value }),
        });
        setAuthTwoFactorEnabled(false);
        showDisableForm.value = false;
        disablePassword.value = '';
        disableCode.value = '';
        disableRecoveryCode.value = '';
        tfaDisabledNotice.value = true;
    } catch (err) {
        tfaHandleError(err);
    } finally {
        tfaBusy.value = false;
    }
}
</script>

<template>
    <AdminLayout>
        <div class="mx-auto max-w-2xl">
            <h1 class="text-2xl font-semibold tracking-tight text-slate-950">{{ t('security.title') }}</h1>
            <p class="mt-1 text-sm text-slate-600">{{ t('security.subtitle') }}</p>

            <!-- P1-15: two-step login is required before anything else. -->
            <div
                v-if="setupRequired"
                class="mt-6 flex items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900"
                role="alert"
                data-testid="two-factor-setup-required"
            >
                <ShieldAlert class="mt-0.5 size-5 shrink-0" />
                <div>
                    <p class="font-semibold">{{ t('security.two_factor.required_title') }}</p>
                    <p class="mt-1">{{ t('security.two_factor.required_body') }}</p>
                </div>
            </div>

            <!-- Identity summary -->
            <div class="mt-6 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex items-center gap-4">
                    <span class="grid size-11 place-items-center rounded-lg bg-slate-950 text-sm font-semibold text-teal-300">
                        <UserRound class="size-5" />
                    </span>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-slate-950">{{ authState.user?.name ?? '—' }}</p>
                        <p class="flex items-center gap-1.5 text-xs text-slate-500">
                            <Mail class="size-3.5 shrink-0" />
                            <span class="truncate">{{ authState.user?.email ?? '—' }}</span>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Two-factor authentication card -->
            <div class="mt-6 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <span
                            class="grid size-11 place-items-center rounded-lg"
                            :class="twoFactorEnabled ? 'bg-teal-50 text-teal-700 ring-1 ring-teal-100' : 'bg-slate-100 text-slate-700'"
                        >
                            <ShieldCheck class="size-5" />
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-slate-950">{{ t('security.two_factor.title') }}</p>
                            <p class="text-xs text-slate-500">{{ t('security.two_factor.subtitle') }}</p>
                        </div>
                    </div>
                    <span
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold"
                        :class="twoFactorEnabled ? 'bg-teal-50 text-teal-800' : 'bg-slate-100 text-slate-600'"
                    >
                        {{ twoFactorEnabled ? t('security.two_factor.status_on') : t('security.two_factor.status_off') }}
                    </span>
                </div>

                <div
                    v-if="tfaError"
                    class="mt-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700"
                >
                    {{ tfaError }}
                </div>
                <div
                    v-else-if="tfaDisabledNotice"
                    class="mt-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-700"
                >
                    {{ t('security.two_factor.disabled_notice') }}
                </div>

                <!-- One-time recovery codes (shown exactly once after enabling) -->
                <template v-if="tfaStep === 'recovery'">
                    <div class="mt-6 rounded-lg border border-teal-200 bg-teal-50 px-4 py-3 text-sm font-semibold text-teal-800">
                        {{ t('security.two_factor.enabled_notice') }}
                    </div>
                    <p class="mt-4 text-sm font-semibold text-slate-800">{{ t('security.two_factor.recovery_title') }}</p>
                    <p class="mt-1 text-xs leading-5 text-slate-500">{{ t('security.two_factor.recovery_note') }}</p>
                    <div class="mt-4 grid grid-cols-2 gap-2 rounded-lg border border-slate-200 bg-slate-50 p-4 font-mono text-sm font-semibold text-slate-800 sm:grid-cols-4">
                        <span v-for="rc in tfaRecoveryCodes" :key="rc" dir="ltr" class="text-center">{{ rc }}</span>
                    </div>
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-teal-50 hover:text-teal-700"
                            @click="copyRecoveryCodes"
                        >
                            <Copy class="size-4" />
                            {{ tfaCopied ? t('security.two_factor.copied') : t('security.two_factor.copy') }}
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center rounded-lg bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800"
                            @click="acknowledgeRecoveryCodes"
                        >
                            {{ t('security.two_factor.saved_codes') }}
                        </button>
                    </div>
                </template>

                <!-- Enrolment: QR + manual secret + confirm code -->
                <template v-else-if="tfaStep === 'enrolling'">
                    <p class="mt-6 text-sm leading-6 text-slate-600">{{ t('security.two_factor.scan_hint') }}</p>
                    <div class="mt-4 flex flex-col items-start gap-5 sm:flex-row">
                        <!-- Inline SVG QR from the server (no extension deps) -->
                        <!-- eslint-disable-next-line vue/no-v-html -->
                        <div class="shrink-0 overflow-hidden rounded-lg border border-slate-200 bg-white p-2" v-html="tfaSvg" />
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                {{ t('security.two_factor.manual_secret') }}
                            </p>
                            <p dir="ltr" class="mt-2 break-all rounded-lg bg-slate-50 px-3 py-2 font-mono text-sm font-semibold text-slate-800">
                                {{ tfaSecret }}
                            </p>
                            <form class="mt-5" @submit.prevent="confirmEnrolment">
                                <label class="block">
                                    <span class="text-sm font-semibold text-slate-800">{{ t('security.two_factor.confirm_label') }}</span>
                                    <input
                                        v-model="tfaConfirmCode"
                                        type="text"
                                        inputmode="numeric"
                                        autocomplete="one-time-code"
                                        pattern="[0-9]*"
                                        maxlength="6"
                                        required
                                        :placeholder="t('security.two_factor.code_placeholder')"
                                        class="mt-2 w-44 rounded-lg border border-slate-200 bg-white px-4 py-3 text-center text-base font-bold tracking-[0.3em] text-slate-950 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                                    >
                                </label>
                                <div class="mt-4 flex items-center gap-3">
                                    <button
                                        type="submit"
                                        :disabled="tfaBusy"
                                        class="inline-flex items-center gap-2 rounded-lg bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-70"
                                    >
                                        <Loader2 v-if="tfaBusy" class="size-4 animate-spin" />
                                        {{ t('security.two_factor.confirm_cta') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="text-sm font-semibold text-slate-500 transition hover:text-slate-800"
                                        @click="cancelEnrolment"
                                    >
                                        {{ t('common.cancel') }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </template>

                <!-- Enabled: disable (step-up) -->
                <template v-else-if="twoFactorEnabled">
                    <p class="mt-5 text-sm leading-6 text-slate-600">{{ t('security.two_factor.enabled_body') }}</p>
                    <button
                        v-if="!showDisableForm"
                        type="button"
                        class="mt-4 inline-flex items-center gap-2 rounded-lg border border-rose-200 bg-white px-4 py-2.5 text-sm font-semibold text-rose-700 shadow-sm transition hover:bg-rose-50"
                        @click="showDisableForm = true; tfaError = null"
                    >
                        <ShieldOff class="size-4" />
                        {{ t('security.two_factor.disable_cta') }}
                    </button>
                    <form v-else class="mt-5 max-w-md space-y-4" @submit.prevent="disableTwoFactor">
                        <p class="text-xs leading-5 text-slate-500">{{ t('security.two_factor.disable_note') }}</p>
                        <label class="block">
                            <span class="text-sm font-semibold text-slate-800">{{ t('security.two_factor.current_password') }}</span>
                            <input
                                v-model="disablePassword"
                                type="password"
                                autocomplete="current-password"
                                required
                                class="mt-2 w-full rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-950 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                            >
                        </label>
                        <label v-if="!disableUseRecovery" class="block">
                            <span class="text-sm font-semibold text-slate-800">{{ t('security.two_factor.confirm_label') }}</span>
                            <input
                                v-model="disableCode"
                                type="text"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                pattern="[0-9]*"
                                maxlength="6"
                                required
                                :placeholder="t('security.two_factor.code_placeholder')"
                                class="mt-2 w-full rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-950 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                            >
                        </label>
                        <label v-else class="block">
                            <span class="text-sm font-semibold text-slate-800">{{ t('security.two_factor.recovery_label') }}</span>
                            <input
                                v-model="disableRecoveryCode"
                                type="text"
                                autocomplete="off"
                                spellcheck="false"
                                maxlength="12"
                                required
                                :placeholder="t('security.two_factor.recovery_placeholder')"
                                class="mt-2 w-full rounded-lg border border-slate-200 bg-white px-4 py-3 font-mono text-sm font-medium uppercase text-slate-950 shadow-sm transition focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                            >
                        </label>
                        <button
                            type="button"
                            class="block text-xs font-semibold text-teal-700 transition hover:text-teal-900"
                            @click="disableUseRecovery = !disableUseRecovery"
                        >
                            {{ disableUseRecovery ? t('security.two_factor.use_code_instead') : t('security.two_factor.use_recovery_instead') }}
                        </button>
                        <div class="flex items-center gap-3">
                            <button
                                type="submit"
                                :disabled="tfaBusy"
                                class="inline-flex items-center gap-2 rounded-lg bg-rose-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-rose-500 disabled:cursor-wait disabled:opacity-70"
                            >
                                <Loader2 v-if="tfaBusy" class="size-4 animate-spin" />
                                {{ t('security.two_factor.disable_confirm') }}
                            </button>
                            <button
                                type="button"
                                class="text-sm font-semibold text-slate-500 transition hover:text-slate-800"
                                @click="showDisableForm = false; tfaError = null"
                            >
                                {{ t('common.cancel') }}
                            </button>
                        </div>
                    </form>
                </template>

                <!-- Disabled: explain + enable -->
                <template v-else>
                    <p class="mt-5 text-sm leading-6 text-slate-600">{{ t('security.two_factor.disabled_body') }}</p>
                    <button
                        type="button"
                        :disabled="tfaBusy"
                        class="mt-4 inline-flex items-center gap-2 rounded-lg bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-70"
                        @click="startEnrolment"
                    >
                        <Loader2 v-if="tfaBusy" class="size-4 animate-spin" />
                        <ShieldCheck v-else class="size-4" />
                        {{ t('security.two_factor.enable_cta') }}
                    </button>
                </template>
            </div>

            <!-- P1-8: change own password (after 2FA is set up). -->
            <div v-if="!setupRequired" class="mt-6 rounded-lg border border-slate-200 bg-white p-6 shadow-sm" data-testid="change-password-card">
                <div class="flex items-center gap-4">
                    <span class="grid size-11 place-items-center rounded-lg bg-slate-100 text-slate-700">
                        <KeyRound class="size-5" />
                    </span>
                    <div>
                        <p class="text-sm font-semibold text-slate-950">{{ t('security.password.title') }}</p>
                        <p class="text-xs text-slate-500">{{ t('security.password.subtitle') }}</p>
                    </div>
                </div>

                <div v-if="passwordError" class="mt-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                    {{ passwordError }}
                </div>
                <div v-else-if="passwordDone" class="mt-5 rounded-lg border border-teal-200 bg-teal-50 px-4 py-3 text-sm font-semibold text-teal-800" role="status">
                    {{ t('security.password.done') }}
                </div>

                <form class="mt-5 max-w-md space-y-4" @submit.prevent="changePassword">
                    <input type="text" name="username" autocomplete="username" :value="authState.user?.email ?? ''" class="sr-only" tabindex="-1" aria-hidden="true" readonly>
                    <div>
                        <label for="security-current-password" class="text-sm font-semibold text-slate-800">{{ t('security.password.current') }}</label>
                        <input
                            id="security-current-password"
                            v-model="passwordForm.current_password"
                            type="password"
                            autocomplete="current-password"
                            required
                            class="mt-2 w-full rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-950 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                        >
                        <p v-if="passwordFieldErrors.current_password" class="mt-1 text-xs text-rose-600">{{ passwordFieldErrors.current_password[0] }}</p>
                    </div>
                    <div>
                        <label for="security-new-password" class="text-sm font-semibold text-slate-800">{{ t('security.password.new') }}</label>
                        <input
                            id="security-new-password"
                            v-model="passwordForm.password"
                            type="password"
                            autocomplete="new-password"
                            minlength="12"
                            required
                            class="mt-2 w-full rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-950 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                        >
                        <p v-if="passwordFieldErrors.password" class="mt-1 text-xs text-rose-600">{{ passwordFieldErrors.password[0] }}</p>
                        <p v-else class="mt-1 text-xs text-slate-500">{{ t('security.password.hint') }}</p>
                    </div>
                    <div>
                        <label for="security-confirm-password" class="text-sm font-semibold text-slate-800">{{ t('security.password.confirm') }}</label>
                        <input
                            id="security-confirm-password"
                            v-model="passwordForm.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            minlength="12"
                            required
                            class="mt-2 w-full rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-950 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                        >
                    </div>
                    <button
                        type="submit"
                        :disabled="passwordBusy"
                        class="inline-flex items-center gap-2 rounded-lg bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-70"
                    >
                        <Loader2 v-if="passwordBusy" class="size-4 animate-spin" />
                        {{ t('security.password.submit') }}
                    </button>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
