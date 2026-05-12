<script setup lang="ts">
import type { Component } from 'vue';

defineProps<{
    label: string;
    value: string;
    change: string;
    tone: 'teal' | 'amber' | 'blue' | 'rose';
    icon: Component;
}>();

const toneClasses = {
    teal: 'bg-teal-50 text-teal-700 ring-teal-100',
    amber: 'bg-amber-50 text-amber-700 ring-amber-100',
    blue: 'bg-sky-50 text-sky-700 ring-sky-100',
    rose: 'bg-rose-50 text-rose-700 ring-rose-100',
} as const;
</script>

<template>
    <article
        class="group rounded-lg border border-slate-200 bg-white p-5 shadow-sm transition duration-300 hover:-translate-y-1 hover:border-slate-300 hover:shadow-xl hover:shadow-slate-200/60"
    >
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-sm font-medium text-slate-500">{{ label }}</p>
                <p class="mt-3 text-3xl font-semibold tracking-tight text-slate-950">{{ value }}</p>
            </div>
            <div
                class="grid size-11 place-items-center rounded-lg ring-1 transition duration-300 group-hover:scale-105"
                :class="toneClasses[tone]"
            >
                <component :is="icon" class="size-5" stroke-width="2" />
            </div>
        </div>
        <p class="mt-5 text-sm font-medium text-emerald-700">{{ change }}</p>
    </article>
</template>
