<script setup lang="ts">
/**
 * Full-screen lightbox for previewing advertiser content (image or video).
 * Click the backdrop, the ✕, or press Escape to close.
 */
import { onBeforeUnmount, onMounted } from 'vue';
import { X } from 'lucide-vue-next';

defineProps<{
    type: 'image' | 'video';
    url: string | null;
    poster?: string | null;
    title?: string;
}>();

const emit = defineEmits<{ (e: 'close'): void }>();

function onKey(e: KeyboardEvent): void {
    if (e.key === 'Escape') emit('close');
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <div class="fixed inset-0 z-[100] flex flex-col bg-slate-950/90 p-4 sm:p-8 backdrop-blur-sm" @click.self="emit('close')">
        <div class="flex items-center justify-between gap-4 pb-3 text-white">
            <p class="truncate text-sm font-semibold">{{ title }}</p>
            <button
                type="button"
                class="grid size-10 shrink-0 place-items-center rounded-full bg-white/10 text-white transition hover:bg-white/20"
                aria-label="Close"
                @click="emit('close')"
            >
                <X class="size-5" />
            </button>
        </div>
        <div class="flex min-h-0 flex-1 items-center justify-center" @click.self="emit('close')">
            <img
                v-if="type === 'image'"
                :src="url ?? ''"
                :alt="title"
                class="max-h-full max-w-full rounded-lg object-contain shadow-2xl"
            >
            <video
                v-else
                controls
                autoplay
                :poster="poster ?? undefined"
                class="max-h-full max-w-full rounded-lg shadow-2xl"
            >
                <source :src="url ?? ''">
            </video>
        </div>
    </div>
</template>
