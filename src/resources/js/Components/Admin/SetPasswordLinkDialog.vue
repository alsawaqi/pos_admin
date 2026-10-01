<script setup lang="ts">
/**
 * LAUNCH-P1 P1-2 / P1-8 — "Copy set-password link" dialog.
 *
 * Shown right after an admin creates a login (merchant portal user or
 * admin) or sends a reset. The server never hands out a password; it
 * returns a single-use link instead, once. This dialog lets the admin
 * copy it (e.g. to send by WhatsApp), says whether it was also emailed,
 * and when it expires. The link is kept in memory only while the dialog
 * is open.
 */
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { CheckCircle2, Copy, KeyRound, MailCheck, MailWarning } from 'lucide-vue-next';
import BaseModal from '@/Components/BaseModal.vue';
import type { SetPasswordLink } from '@/lib/api/setPasswordLink';

const props = defineProps<{
    link: SetPasswordLink;
    userName: string;
    userEmail: string;
}>();

const emit = defineEmits<{ (e: 'close'): void }>();

const { t, locale } = useI18n();
const copied = ref(false);
const copyFailed = ref(false);

const expiresLabel = computed(() => new Date(props.link.expires_at).toLocaleString(locale.value === 'ar' ? 'ar-OM' : 'en-GB', {
    dateStyle: 'medium',
    timeStyle: 'short',
}));

const validityLabel = computed(() => (props.link.purpose === 'invite'
    ? t('set_password_link.valid_invite')
    : t('set_password_link.valid_reset')));

const deliveryTone = computed(() => (props.link.emailed ? 'ok' : 'warn'));
const deliveryText = computed(() => {
    if (props.link.emailed) {
        return t('set_password_link.emailed', { email: props.userEmail });
    }
    if (props.link.email_error) {
        return t('set_password_link.email_failed');
    }
    return t('set_password_link.not_emailed');
});

async function copyLink(): Promise<void> {
    copyFailed.value = false;
    try {
        await navigator.clipboard.writeText(props.link.url);
        copied.value = true;
        window.setTimeout(() => { copied.value = false; }, 2000);
    } catch {
        // Clipboard API blocked (non-HTTPS / permissions): select the
        // text so the admin can copy it by hand.
        copyFailed.value = true;
        const input = document.getElementById('set-password-link-url') as HTMLInputElement | null;
        input?.select();
    }
}
</script>

<template>
    <BaseModal
        :title="link.purpose === 'invite' ? t('set_password_link.title_invite') : t('set_password_link.title_reset')"
        size="lg"
        :close-on-backdrop="false"
        @close="emit('close')"
    >
        <template #icon>
            <span class="grid size-10 shrink-0 place-items-center rounded-full bg-teal-100 text-teal-700">
                <KeyRound class="size-5" />
            </span>
        </template>

        <div class="space-y-4" data-testid="set-password-link-dialog">
            <p class="text-sm text-slate-700">
                {{ t('set_password_link.intro', { name: userName }) }}
            </p>

            <div
                class="flex items-start gap-2 rounded-lg border px-3 py-2 text-sm font-semibold"
                :class="deliveryTone === 'ok' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-800'"
                role="status"
            >
                <MailCheck v-if="deliveryTone === 'ok'" class="mt-0.5 size-4 shrink-0" />
                <MailWarning v-else class="mt-0.5 size-4 shrink-0" />
                <span>{{ deliveryText }}</span>
            </div>

            <div>
                <label for="set-password-link-url" class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    {{ t('set_password_link.link_label') }}
                </label>
                <div class="mt-1 flex gap-2">
                    <input
                        id="set-password-link-url"
                        :value="link.url"
                        readonly
                        class="min-w-0 flex-1 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-xs text-slate-800"
                        @focus="($event.target as HTMLInputElement).select()"
                    >
                    <button
                        type="button"
                        class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-slate-950 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800"
                        data-testid="copy-set-password-link"
                        @click="copyLink"
                    >
                        <CheckCircle2 v-if="copied" class="size-4" />
                        <Copy v-else class="size-4" />
                        {{ copied ? t('set_password_link.copied') : t('set_password_link.copy') }}
                    </button>
                </div>
                <p v-if="copyFailed" class="mt-1 text-xs font-semibold text-rose-700">{{ t('set_password_link.copy_failed') }}</p>
            </div>

            <dl class="grid gap-1 text-sm">
                <div class="flex gap-2">
                    <dt class="text-slate-500">{{ t('set_password_link.expires') }}</dt>
                    <dd class="font-semibold text-slate-800">{{ expiresLabel }} ({{ validityLabel }})</dd>
                </div>
            </dl>

            <p class="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                {{ t('set_password_link.warning', { email: userEmail }) }}
            </p>
        </div>

        <template #footer>
            <div class="flex justify-end">
                <button
                    type="button"
                    class="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                    @click="emit('close')"
                >
                    {{ t('set_password_link.done') }}
                </button>
            </div>
        </template>
    </BaseModal>
</template>
