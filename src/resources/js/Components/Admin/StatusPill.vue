<script setup lang="ts">
export type StatusTone = 'green' | 'amber' | 'slate' | 'red' | 'rose' | 'blue' | 'sky';

defineProps<{
    label: string;
    tone: StatusTone;
}>();

// `red`/`rose` and `blue`/`sky` are aliases so call sites can use either
// spelling — pages historically passed both, which previously left some
// pills unstyled.
const toneClasses: Record<StatusTone, string> = {
    green: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    amber: 'bg-amber-50 text-amber-700 ring-amber-200',
    slate: 'bg-slate-100 text-slate-700 ring-slate-200',
    red: 'bg-rose-50 text-rose-700 ring-rose-200',
    rose: 'bg-rose-50 text-rose-700 ring-rose-200',
    blue: 'bg-sky-50 text-sky-700 ring-sky-200',
    sky: 'bg-sky-50 text-sky-700 ring-sky-200',
};

const dotClasses: Record<StatusTone, string> = {
    green: 'bg-emerald-500',
    amber: 'bg-amber-500',
    slate: 'bg-slate-400',
    red: 'bg-rose-500',
    rose: 'bg-rose-500',
    blue: 'bg-sky-500',
    sky: 'bg-sky-500',
};
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1"
        :class="toneClasses[tone]"
    >
        <span class="size-1.5 rounded-full" :class="dotClasses[tone]" />
        {{ label }}
    </span>
</template>
