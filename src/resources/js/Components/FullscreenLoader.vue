<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next';

withDefaults(
    defineProps<{
        /** Toggle to mount/unmount the overlay. */
        visible: boolean;
        /** Optional caption rendered under the spinner. */
        message?: string;
    }>(),
    { message: '' },
);
</script>

<!--
  Generic full-viewport blocking loader.

  Used for any boundary-crossing action where the user MUST see immediate
  acknowledgement (logout, long-running form submit, full-page navigation
  that the browser has not yet committed to). Renders via Teleport so it
  always sits above the rest of the app regardless of z-index in the
  surrounding component tree.

  The button that triggers this should also be disabled while `visible`
  is true so the user cannot fire the same action twice while waiting.
-->
<template>
    <Teleport to="body">
        <Transition name="fullscreen-loader">
            <div
                v-if="visible"
                class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/70 backdrop-blur-md"
                role="status"
                aria-live="polite"
                aria-busy="true"
            >
                <div class="flex flex-col items-center gap-4 rounded-2xl bg-white px-10 py-8 shadow-2xl ring-1 ring-slate-200">
                    <Loader2 class="size-10 animate-spin text-teal-600" aria-hidden="true" />
                    <p v-if="message" class="text-sm font-semibold text-slate-700">{{ message }}</p>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.fullscreen-loader-enter-active,
.fullscreen-loader-leave-active {
    transition: opacity 180ms ease-out;
}

.fullscreen-loader-enter-from,
.fullscreen-loader-leave-to {
    opacity: 0;
}
</style>
