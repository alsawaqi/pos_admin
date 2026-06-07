<script setup lang="ts">
/**
 * Reusable confirmation modal. Shared by every destructive action
 * (delete branch, decommission device, etc.) so the UX is identical
 * across the admin portal.
 *
 * Built on the shared {@see BaseModal} — centering, teleport, scroll-lock,
 * Escape / backdrop dismissal (suppressed while `loading`) and the
 * enter/leave animation all live there. This component only adds the
 * danger framing + the confirm/cancel buttons.
 *
 * Closes via Cancel, Escape, the backdrop, the × button, or the parent
 * setting its `v-if` to false after success. Does NOT close on Confirm —
 * the parent decides (typically after the API call), keeping the spinner
 * visible during the async operation.
 */

import { AlertTriangle, Loader2 } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import BaseModal from '@/Components/BaseModal.vue';

withDefaults(
    defineProps<{
        title: string;
        message: string;
        confirmLabel?: string;
        cancelLabel?: string;
        /** Tone of the primary button — danger renders red, primary stays slate. */
        tone?: 'danger' | 'primary';
        loading?: boolean;
        /** Inline error from the last attempt (e.g. 409 with reason). */
        error?: string | null;
    }>(),
    {
        confirmLabel: undefined,
        cancelLabel: undefined,
        tone: 'danger',
        loading: false,
        error: null,
    },
);

const emit = defineEmits<{
    (e: 'confirm'): void;
    (e: 'cancel'): void;
}>();

const { t } = useI18n();
</script>

<template>
    <BaseModal :title="title" size="md" :loading="loading" @close="emit('cancel')">
        <template #icon>
            <span
                class="grid size-10 shrink-0 place-items-center rounded-full"
                :class="tone === 'danger' ? 'bg-rose-100 text-rose-700' : 'bg-slate-200 text-slate-700'"
            >
                <AlertTriangle class="size-5" />
            </span>
        </template>

        <p class="text-sm leading-6 text-slate-700">{{ message }}</p>
        <p v-if="error" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
            {{ error }}
        </p>

        <template #footer>
            <div class="flex justify-end gap-2">
                <button
                    type="button"
                    class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 disabled:cursor-wait disabled:opacity-50"
                    :disabled="loading"
                    @click="emit('cancel')"
                >
                    {{ cancelLabel ?? t('common.cancel') }}
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white shadow-sm transition disabled:cursor-wait disabled:opacity-60"
                    :class="tone === 'danger'
                        ? 'bg-rose-600 hover:bg-rose-700'
                        : 'bg-slate-950 hover:bg-slate-800'"
                    :disabled="loading"
                    @click="emit('confirm')"
                >
                    <Loader2 v-if="loading" class="size-4 animate-spin" />
                    {{ confirmLabel ?? t('common.delete') }}
                </button>
            </div>
        </template>
    </BaseModal>
</template>
