<script setup lang="ts">
/**
 * Reusable confirmation modal. Shared by every destructive action
 * (delete branch, decommission device, etc.) so the UX is identical
 * across the admin portal — same backdrop, same button shape, same
 * danger tone, same focus trap behaviour.
 *
 * Usage:
 *   <ConfirmDialog
 *     v-if="confirmTarget"
 *     :title="t('branches.delete.title')"
 *     :message="t('branches.delete.message', { name: confirmTarget.name })"
 *     :confirm-label="t('common.delete')"
 *     :loading="busy"
 *     :error="confirmError"
 *     @confirm="handleConfirm"
 *     @cancel="confirmTarget = null"
 *   />
 *
 * Closes via:
 *   - Clicking Cancel
 *   - Pressing Escape
 *   - Clicking the backdrop
 *   - The parent setting `v-if` to false after success
 *
 * Does NOT close on Confirm — the parent decides (typically after
 * the API call succeeds). This keeps the spinner visible during
 * the async operation.
 */

import { AlertTriangle, Loader2, X } from 'lucide-vue-next';
import { onMounted, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';

const props = withDefaults(
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

// Escape-to-cancel — only register while the dialog is mounted so
// we don't leak listeners.
function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && !props.loading) {
        emit('cancel');
    }
}
onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

function onBackdropClick(): void {
    // Block backdrop dismissal mid-request so a slow click doesn't
    // strand the parent in "did it succeed or did the user cancel?"
    if (!props.loading) {
        emit('cancel');
    }
}
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/40 p-4 backdrop-blur-sm" @click.self="onBackdropClick">
        <div class="w-full max-w-md overflow-hidden rounded-xl bg-white shadow-2xl" @click.stop>
            <header class="flex items-start gap-4 border-b border-slate-200 bg-slate-50 px-6 py-5">
                <span
                    class="grid size-10 shrink-0 place-items-center rounded-full"
                    :class="tone === 'danger' ? 'bg-rose-100 text-rose-700' : 'bg-slate-200 text-slate-700'"
                >
                    <AlertTriangle class="size-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <h2 class="text-base font-semibold text-slate-950">{{ title }}</h2>
                </div>
                <button
                    type="button"
                    :aria-label="cancelLabel ?? t('common.cancel')"
                    class="grid size-9 place-items-center rounded-lg text-slate-500 transition hover:bg-slate-200/60 hover:text-slate-700 disabled:opacity-50"
                    :disabled="loading"
                    @click="emit('cancel')"
                >
                    <X class="size-4" />
                </button>
            </header>

            <div class="space-y-4 px-6 py-5">
                <p class="text-sm leading-6 text-slate-700">{{ message }}</p>

                <p v-if="error" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                    {{ error }}
                </p>
            </div>

            <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-6 py-4">
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
        </div>
    </div>
</template>
