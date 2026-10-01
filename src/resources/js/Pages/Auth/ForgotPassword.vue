<script setup lang="ts">
/**
 * Admin "Forgot password?" (LAUNCH-P1 P1-8) — guest page.
 *
 * POSTs the email to /auth/forgot-password. The server answers the same
 * way whether or not the email is an admin (no account discovery), and
 * emails a 60-minute reset link when it is.
 */
import { ArrowLeft, Loader2, Mail, MailCheck } from 'lucide-vue-next';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError, apiPost } from '@/lib/api';

const { t } = useI18n();

const email = ref('');
const submitting = ref(false);
const sent = ref(false);
const errorMessage = ref<string | null>(null);

async function onSubmit(): Promise<void> {
    errorMessage.value = null;
    submitting.value = true;
    try {
        await apiPost('/auth/forgot-password', { email: email.value }, { skipAuthInterceptor: true });
        sent.value = true;
    } catch (err) {
        errorMessage.value = err instanceof ApiError && err.isValidationError()
            ? err.firstValidationMessage()
            : t('auth.forgot.error_generic');
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <main class="grid min-h-screen place-items-center bg-slate-50 px-4 py-12">
        <section class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 shadow-xl">
            <template v-if="sent">
                <span class="grid size-12 place-items-center rounded-xl bg-teal-50 text-teal-700">
                    <MailCheck class="size-6" />
                </span>
                <h1 class="mt-5 text-2xl font-semibold tracking-tight text-slate-950">{{ t('auth.forgot.sent_title') }}</h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ t('auth.forgot.sent_body', { email }) }}</p>
            </template>

            <template v-else>
                <h1 class="text-2xl font-semibold tracking-tight text-slate-950">{{ t('auth.forgot.title') }}</h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ t('auth.forgot.subtitle') }}</p>

                <p v-if="errorMessage" class="mt-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700" role="alert">
                    {{ errorMessage }}
                </p>

                <form class="mt-6 space-y-4" @submit.prevent="onSubmit">
                    <div>
                        <label for="forgot-email" class="text-sm font-semibold text-slate-800">{{ t('auth.email') }}</label>
                        <div class="relative mt-2">
                            <Mail class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                            <input
                                id="forgot-email"
                                v-model="email"
                                type="email"
                                autocomplete="email"
                                required
                                class="w-full rounded-lg border border-slate-200 bg-white py-3 pe-4 ps-10 text-sm font-medium text-slate-950 shadow-sm focus:border-teal-500 focus:outline-none focus:ring-4 focus:ring-teal-100"
                            >
                        </div>
                    </div>
                    <button
                        type="submit"
                        :disabled="submitting"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-slate-950 px-5 py-3.5 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-wait disabled:opacity-70"
                    >
                        <Loader2 v-if="submitting" class="size-4 animate-spin" />
                        {{ submitting ? t('auth.forgot.submitting') : t('auth.forgot.submit') }}
                    </button>
                </form>
            </template>

            <RouterLink to="/login" class="mt-6 inline-flex items-center gap-2 text-sm font-semibold text-slate-500 hover:text-slate-800">
                <ArrowLeft class="size-4 rtl:rotate-180" />
                {{ t('auth.forgot.back_to_login') }}
            </RouterLink>
        </section>
    </main>
</template>
