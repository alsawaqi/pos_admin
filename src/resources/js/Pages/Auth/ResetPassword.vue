<script setup lang="ts">
/**
 * Admin set / reset password page (LAUNCH-P1 P1-8) — guest page reached
 * from a set-password link: /set-password?token=…&email=… (a new admin's
 * invite, mode "set") or /reset-password?token=…&email=… (an admin reset
 * or forgot-password, mode "reset").
 *
 * POSTs {email, token, password, password_confirmation} to
 * /auth/reset-password. A missing, wrong, used or expired link shows the
 * dead-link state. After success the admin signs in and, if they have no
 * authenticator yet, is taken straight to the two-step login setup.
 */
import { ArrowRight, CheckCircle2, Loader2, Lock, TriangleAlert } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import { ApiError, apiPost } from '@/lib/api';

const props = withDefaults(defineProps<{ mode?: 'reset' | 'set' }>(), { mode: 'reset' });

const { t } = useI18n();
const route = useRoute();

const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''));
const email = computed(() => (typeof route.query.email === 'string' ? route.query.email : ''));
const linkBroken = computed(() => token.value === '' || email.value === '');
const prefix = computed(() => (props.mode === 'set' ? 'auth.set_password' : 'auth.reset_password'));

const password = ref('');
const confirmPassword = ref('');
const submitting = ref(false);
const done = ref(false);
const tokenRejected = ref(false);
const errorMessage = ref<string | null>(null);
const fieldError = ref<string | null>(null);

async function onSubmit(): Promise<void> {
    errorMessage.value = null;
    fieldError.value = null;
    if (password.value !== confirmPassword.value) {
        fieldError.value = t('auth.reset_password.mismatch');
        return;
    }
    submitting.value = true;
    try {
        await apiPost('/auth/reset-password', {
            email: email.value,
            token: token.value,
            password: password.value,
            password_confirmation: confirmPassword.value,
        }, { skipAuthInterceptor: true });
        done.value = true;
    } catch (err) {
        if (err instanceof ApiError && err.isValidationError()) {
            if (err.payload.errors.token) {
                tokenRejected.value = true;
            } else {
                fieldError.value = err.payload.errors.password?.[0] ?? null;
                errorMessage.value = err.firstValidationMessage();
            }
        } else {
            errorMessage.value = t('auth.reset_password.error_generic');
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <main class="grid min-h-screen place-items-center bg-slate-50 px-4 py-12">
        <section class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 shadow-xl">
            <template v-if="done">
                <span class="grid size-12 place-items-center rounded-xl bg-teal-50 text-teal-700">
                    <CheckCircle2 class="size-6" />
                </span>
                <h1 class="mt-5 text-2xl font-semibold tracking-tight text-slate-950">{{ t(`${prefix}.success_title`) }}</h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ t(`${prefix}.success_body`) }}</p>
                <a href="/login" class="mt-6 inline-flex w-full items-center justify-center gap-2 rounded-lg bg-slate-950 px-5 py-3.5 text-sm font-semibold text-white hover:bg-slate-800">
                    {{ t('auth.reset_password.go_to_login') }}
                    <ArrowRight class="size-4 rtl:rotate-180" />
                </a>
            </template>

            <template v-else-if="linkBroken || tokenRejected">
                <span class="grid size-12 place-items-center rounded-xl bg-amber-50 text-amber-600">
                    <TriangleAlert class="size-6" />
                </span>
                <h1 class="mt-5 text-2xl font-semibold tracking-tight text-slate-950">{{ t('auth.reset_password.invalid_title') }}</h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ t(`${prefix}.invalid_body`) }}</p>
                <RouterLink to="/forgot-password" class="mt-6 inline-flex w-full items-center justify-center rounded-lg bg-slate-950 px-5 py-3.5 text-sm font-semibold text-white hover:bg-slate-800">
                    {{ t('auth.reset_password.request_new') }}
                </RouterLink>
            </template>

            <template v-else>
                <h1 class="text-2xl font-semibold tracking-tight text-slate-950">{{ t(`${prefix}.title`) }}</h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ t(`${prefix}.subtitle`, { email }) }}</p>

                <p v-if="errorMessage" class="mt-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700" role="alert">
                    {{ errorMessage }}
                </p>

                <form class="mt-6 space-y-4" @submit.prevent="onSubmit">
                    <input type="text" name="username" autocomplete="username" :value="email" class="sr-only" tabindex="-1" aria-hidden="true" readonly>
                    <div>
                        <label for="reset-new-password" class="text-sm font-semibold text-slate-800">{{ t('auth.reset_password.new') }}</label>
                        <div class="relative mt-2">
                            <Lock class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                            <input
                                id="reset-new-password"
                                v-model="password"
                                type="password"
                                autocomplete="new-password"
                                minlength="12"
                                required
                                class="w-full rounded-lg border border-slate-200 bg-white py-3 pe-4 ps-10 text-sm font-medium text-slate-950 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                            >
                        </div>
                        <p v-if="fieldError" class="mt-1 text-xs font-semibold text-rose-600">{{ fieldError }}</p>
                        <p v-else class="mt-1 text-xs text-slate-500">{{ t('auth.reset_password.hint') }}</p>
                    </div>
                    <div>
                        <label for="reset-confirm-password" class="text-sm font-semibold text-slate-800">{{ t('auth.reset_password.confirm') }}</label>
                        <input
                            id="reset-confirm-password"
                            v-model="confirmPassword"
                            type="password"
                            autocomplete="new-password"
                            minlength="12"
                            required
                            class="mt-2 w-full rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-950 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                        >
                    </div>
                    <button
                        type="submit"
                        :disabled="submitting"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-slate-950 px-5 py-3.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-70"
                    >
                        <Loader2 v-if="submitting" class="size-4 animate-spin" />
                        {{ submitting ? t('auth.reset_password.submitting') : t(`${prefix}.submit`) }}
                    </button>
                </form>
            </template>
        </section>
    </main>
</template>
