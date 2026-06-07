<script setup lang="ts">
import type { Component } from 'vue';

defineProps<{
    label: string;
    value: string;
    change: string;
    tone: 'teal' | 'amber' | 'blue' | 'rose';
    icon: Component;
}>();

// Gradient icon chip per tone for a little more depth/presence.
const toneClasses = {
    teal: 'from-teal-50 to-teal-100/70 text-teal-700 ring-teal-200/70',
    amber: 'from-amber-50 to-amber-100/70 text-amber-700 ring-amber-200/70',
    blue: 'from-sky-50 to-sky-100/70 text-sky-700 ring-sky-200/70',
    rose: 'from-rose-50 to-rose-100/70 text-rose-700 ring-rose-200/70',
} as const;
</script>

<template>
    <article
        class="group rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition duration-300 hover:-translate-y-1 hover:border-slate-300 hover:shadow-xl hover:shadow-slate-200/60"
    >
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-sm font-medium text-slate-500">{{ label }}</p>
                <p class="mt-3 text-3xl font-semibold tracking-tight text-slate-950">{{ value }}</p>
            </div>
            <div
                class="grid size-11 place-items-center rounded-xl bg-gradient-to-br ring-1 transition duration-300 group-hover:scale-110 group-hover:-rotate-3"
                :class="toneClasses[tone]"
            >
                <component :is="icon" class="size-5" stroke-width="2" />
            </div>
        </div>
        <p class="mt-5 text-sm font-medium text-emerald-700">{{ change }}</p>
    </article>
</template>
