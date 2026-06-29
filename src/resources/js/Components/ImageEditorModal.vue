<script setup lang="ts">
/**
 * Full-screen Filerobot image editor (same engine as the marketing-api portal).
 * Edits an admin-owned slider IMAGE in place: on save it hands the parent a new
 * File (the edited canvas) to send to the replace endpoint. Filerobot bundles
 * React, so it is dynamically imported here — it only loads when the editor is
 * actually opened, keeping it out of the main admin bundle.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{ src: string; title?: string }>();
const emit = defineEmits<{ (e: 'save', file: File): void; (e: 'close'): void }>();

const container = ref<HTMLDivElement | null>(null);
const error = ref<string | null>(null);
const saving = ref(false);
let saved = false;
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let editor: any = null;

onMounted(async () => {
    try {
        const mod = await import('filerobot-image-editor');
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const FilerobotImageEditor: any = (mod as any).default ?? mod;
        if (!container.value) return;

        editor = new FilerobotImageEditor(container.value, {
            source: props.src,
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            onSave: (edited: any) => {
                void onSave(edited);
            },
            annotationsCommon: { fill: '#0d9488' },
            Text: { text: 'Text' },
            savingPixelRatio: 1,
            previewPixelRatio: Math.min(window.devicePixelRatio || 1, 2),
        });

        editor.render({
            onClose: () => {
                if (!saving.value && !saved) emit('close');
            },
        });
    } catch {
        error.value = 'Could not load the image editor.';
    }
});

onBeforeUnmount(() => {
    try {
        editor?.terminate?.();
    } catch {
        // ignore
    }
});

// eslint-disable-next-line @typescript-eslint/no-explicit-any
async function onSave(edited: any): Promise<void> {
    saving.value = true;
    error.value = null;
    try {
        const file = await toFile(edited);
        saved = true;
        emit('save', file);
    } catch {
        error.value = 'Could not save the edited image.';
    } finally {
        saving.value = false;
    }
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
async function toFile(edited: any): Promise<File> {
    // The backend only accepts jpg/png — normalise anything else to png.
    let mime: string = edited?.mimeType || 'image/png';
    if (mime !== 'image/jpeg' && mime !== 'image/png') mime = 'image/png';
    const ext = mime === 'image/jpeg' ? 'jpg' : 'png';

    const canvas: HTMLCanvasElement = edited?.imageCanvas;
    const blob = await new Promise<Blob | null>((resolve) =>
        canvas.toBlob((b) => resolve(b), mime, edited?.quality ?? 0.92),
    );
    if (!blob) throw new Error('No blob');

    const base = (props.title || 'edited').replace(/[^\w.-]+/g, '-').slice(0, 60) || 'edited';
    return new File([blob], `${base}.${ext}`, { type: mime });
}
</script>

<template>
    <div class="fixed inset-0 z-50 bg-slate-900/80">
        <div class="absolute inset-2 overflow-hidden rounded-xl bg-white sm:inset-6">
            <div v-if="error" class="grid h-full place-items-center gap-3 p-6 text-center">
                <p class="text-sm font-medium text-rose-600">{{ error }}</p>
                <button type="button" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50" @click="emit('close')">Close</button>
            </div>
            <div ref="container" class="size-full"></div>
        </div>
    </div>
</template>
