<script setup lang="ts">
defineProps<{
    label: string;
    tone: 'green' | 'amber' | 'slate' | 'red' | 'blue';
}>();

const toneClasses = {
    green: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    amber: 'bg-amber-50 text-amber-700 ring-amber-200',
    slate: 'bg-slate-100 text-slate-700 ring-slate-200',
    red: 'bg-rose-50 text-rose-700 ring-rose-200',
    blue: 'bg-sky-50 text-sky-700 ring-sky-200',
} as const;
</script>

<template>
    <span
        class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1"
        :class="toneClasses[tone]"
    >
        {{ label }}
    </span>
</template>
