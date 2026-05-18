<script setup lang="ts">
import {
    Eye,
    EyeOff,
    LockKeyhole,
    Mail,
    ShieldCheck,
    Sparkles,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import { useFreshCsrfNativePost } from '@/composables/useFreshCsrfNativePost';
import { consumeServerFlash, firstFlashMessage } from '@/lib/serverFlash';

const { t } = useI18n();
const route = useRoute();

const showPassword = ref(false);
const email = ref('');
const password = ref('');
const remember = ref(false);
const errorMessage = ref<string | null>(null);
const csrfToken = ref('');
const {
    isSubmitting,
    submitWithFreshCsrf,
} = useFreshCsrfNativePost(csrfToken);

const sessionExpired = computed(() => route.query.expired === '1');

/**
 * The form submits NATIVELY (no @submit.prevent). The browser POSTs to
 * /auth/login and follows the server's 302. We never call XHR for this
 * boundary — eliminates every race condition we hit with the old flow.
 *
 * Errors and old input come back via Laravel's redirect-with-flash,
 * surfaced by Blade as window.__SERVER_FLASH__ and consumed here.
 */
onMounted(() => {
    const metaToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    csrfToken.value = metaToken;

    const flash = consumeServerFlash();
    const flashMessage = firstFlashMessage(flash.errors);

    if (flashMessage !== null) {
        errorMessage.value = flashMessage;
    }

    const oldEmail = flash.old?.email;
    if (typeof oldEmail === 'string') {
        email.value = oldEmail;
    }

    const oldRemember = flash.old?.remember;
    if (oldRemember === true || oldRemember === '1' || oldRemember === 'on') {
        remember.value = true;
    }
});

/**
 * Prevent double-submit. We do NOT preventDefault — the browser still
 * submits the form. We just lock the button and let navigation take it
 * from here.
 */
function onSubmit(event: SubmitEvent): void {
    errorMessage.value = null;
    void submitWithFreshCsrf(event);
}
</script>

<template>
    <main class="min-h-screen bg-slate-950 text-white">
        <section class="grid min-h-screen lg:grid-cols-[1.05fr_0.95fr]">
            <div class="relative hidden overflow-hidden lg:block">
                <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_left,_rgba(20,184,166,0.28),_transparent_34%),linear-gradient(135deg,_#020617_0%,_#0f172a_50%,_#134e4a_100%)]" />
                <div class="absolute inset-0 opacity-20 login-grid" />

                <div class="relative flex h-full flex-col justify-between p-12">
                    <a href="/admin" class="flex items-center gap-3">
                        <span class="grid size-11 place-items-center rounded-lg bg-teal-400 text-base font-black text-slate-950">
                            M
                        </span>
                        <span>
                            <span class="block text-sm font-semibold uppercase tracking-[0.2em] text-teal-200">MITHQAL</span>
                            <span class="block text-xl font-semibold">POS Admin</span>
                        </span>
                    </a>

                    <div class="max-w-xl">
                        <div class="inline-flex items-center gap-2 rounded-full border border-teal-300/20 bg-teal-300/10 px-3 py-2 text-sm font-semibold text-teal-100">
                            <ShieldCheck class="size-4" />
                            Secure platform workspace
                        </div>
                        <h1 class="mt-8 text-5xl font-semibold leading-tight tracking-tight">
                            Merchant onboarding, devices, and controls in one command center.
                        </h1>
                        <p class="mt-5 max-w-lg text-base leading-8 text-slate-300">
                            Built for the MITHQAL team to prepare companies, branches, POS devices, and operational readiness before merchant access begins.
                        </p>
                    </div>

                    <div class="grid max-w-2xl grid-cols-3 gap-3">
                        <div class="rounded-lg border border-white/10 bg-white/10 p-4 backdrop-blur">
                            <p class="text-2xl font-semibold">128</p>
                            <p class="mt-1 text-xs font-medium text-slate-300">Merchants</p>
                        </div>
                        <div class="rounded-lg border border-white/10 bg-white/10 p-4 backdrop-blur">
                            <p class="text-2xl font-semibold">419</p>
                            <p class="mt-1 text-xs font-medium text-slate-300">POS Devices</p>
                        </div>
                        <div class="rounded-lg border border-white/10 bg-white/10 p-4 backdrop-blur">
                            <p class="text-2xl font-semibold">99.9%</p>
                            <p class="mt-1 text-xs font-medium text-slate-300">Uptime</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex min-h-screen items-center justify-center bg-slate-50 px-5 py-10 text-slate-950">
                <div class="w-full max-w-md login-card">
                    <div class="mb-8 flex items-center justify-between lg:hidden">
                        <a href="/admin" class="flex items-center gap-3">
                            <span class="grid size-10 place-items-center rounded-lg bg-slate-950 text-base font-black text-teal-300">
                                M
                            </span>
                            <span class="text-lg font-semibold">POS Admin</span>
                        </a>
                    </div>

                    <div class="rounded-lg border border-slate-200 bg-white p-7 shadow-2xl shadow-slate-200/70 sm:p-8">
                        <div class="flex items-center gap-3">
                            <span class="grid size-11 place-items-center rounded-lg bg-teal-50 text-teal-700 ring-1 ring-teal-100">
                                <Sparkles class="size-5" />
                            </span>
                            <div>
                                <h2 class="text-2xl font-semibold tracking-tight">{{ t('auth.welcome_back') }}</h2>
                                <p class="mt-1 text-sm text-slate-500">{{ t('auth.sign_in_subtitle') }}</p>
                            </div>
                        </div>

                        <form
                            class="mt-8 space-y-5"
                            method="POST"
                            action="/auth/login"
                            @submit="onSubmit"
                        >
                            <input type="hidden" name="_token" :value="csrfToken">

                            <div
                                v-if="sessionExpired"
                                class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800"
                            >
                                {{ t('auth.session_expired') }}
                            </div>

                            <div
                                v-if="errorMessage"
                                class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700"
                            >
                                {{ errorMessage }}
                            </div>

                            <label class="block">
                                <span class="text-sm font-semibold text-slate-700">{{ t('auth.email') }}</span>
                                <span class="mt-2 flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 transition focus-within:border-teal-500 focus-within:bg-white focus-within:ring-4 focus-within:ring-teal-100">
                                    <Mail class="size-5 shrink-0 text-slate-400" />
                                    <input
                                        v-model="email"
                                        type="email"
                                        name="email"
                                        autocomplete="username"
                                        required
                                        :placeholder="t('auth.email')"
                                        class="w-full bg-transparent text-sm font-medium text-slate-950 outline-none placeholder:text-slate-400"
                                    >
                                </span>
                            </label>

                            <label class="block">
                                <span class="text-sm font-semibold text-slate-700">{{ t('auth.password') }}</span>
                                <span class="mt-2 flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-3 transition focus-within:border-teal-500 focus-within:bg-white focus-within:ring-4 focus-within:ring-teal-100">
                                    <LockKeyhole class="size-5 shrink-0 text-slate-400" />
                                    <input
                                        v-model="password"
                                        :type="showPassword ? 'text' : 'password'"
                                        name="password"
                                        autocomplete="current-password"
                                        required
                                        :placeholder="t('auth.password')"
                                        class="w-full bg-transparent text-sm font-medium text-slate-950 outline-none placeholder:text-slate-400"
                                    >
                                    <button
                                        type="button"
                                        class="grid size-8 shrink-0 place-items-center rounded-lg text-slate-400 transition hover:bg-white hover:text-slate-700"
                                        :aria-label="showPassword ? 'Hide password' : 'Show password'"
                                        @click="showPassword = !showPassword"
                                    >
                                        <EyeOff v-if="showPassword" class="size-4" />
                                        <Eye v-else class="size-4" />
                                    </button>
                                </span>
                            </label>

                            <div class="flex items-center justify-between gap-4">
                                <label class="flex items-center gap-2 text-sm font-medium text-slate-600">
                                    <input
                                        v-model="remember"
                                        type="checkbox"
                                        name="remember"
                                        class="size-4 rounded border-slate-300 text-teal-700 focus:ring-teal-500"
                                    >
                                    {{ t('auth.remember_me') }}
                                </label>
                                <a href="#" class="text-sm font-semibold text-teal-700 transition hover:text-teal-900">
                                    {{ t('auth.forgot_password') }}
                                </a>
                            </div>

                            <button
                                type="submit"
                                :disabled="isSubmitting"
                                class="w-full rounded-lg bg-slate-950 px-5 py-3.5 text-sm font-semibold text-white shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-slate-800 hover:shadow-xl focus:outline-none focus:ring-4 focus:ring-slate-300"
                                :class="{ 'cursor-wait opacity-70 hover:translate-y-0 hover:bg-slate-950': isSubmitting }"
                            >
                                {{ isSubmitting ? t('auth.signing_in') : t('auth.sign_in') }}
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </section>
    </main>
</template>

<style scoped>
.login-grid {
    background-image:
        linear-gradient(rgba(255, 255, 255, 0.12) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255, 255, 255, 0.12) 1px, transparent 1px);
    background-size: 44px 44px;
}

.login-card {
    animation: login-in 520ms ease-out both;
}

@keyframes login-in {
    from {
        opacity: 0;
        transform: translateY(14px) scale(0.98);
    }

    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}
</style>
