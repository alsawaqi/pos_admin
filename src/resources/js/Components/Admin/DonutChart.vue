<script setup lang="ts">
import { computed } from 'vue';

interface DonutSegment {
    label: string;
    value: number;
    color: string;
}

const props = defineProps<{
    segments: DonutSegment[];
}>();

const circumference = 100;

const total = computed(() => props.segments.reduce((sum, segment) => sum + segment.value, 0));

const normalizedSegments = computed(() => {
    let offset = 0;

    return props.segments.map((segment) => {
        const percentage = total.value === 0 ? 0 : (segment.value / total.value) * circumference;
        const normalized = {
            ...segment,
            percentage,
            offset,
        };

        offset += percentage;

        return normalized;
    });
});
</script>

<template>
    <div class="grid gap-5 md:grid-cols-[150px_1fr] md:items-center">
        <div class="relative mx-auto size-36">
            <svg viewBox="0 0 42 42" class="size-36 -rotate-90">
                <circle
                    cx="21"
                    cy="21"
                    r="15.915"
                    fill="transparent"
                    stroke="rgb(226 232 240)"
                    stroke-width="5"
                />
                <circle
                    v-for="segment in normalizedSegments"
                    :key="segment.label"
                    cx="21"
                    cy="21"
                    r="15.915"
                    fill="transparent"
                    :stroke="segment.color"
                    stroke-width="5"
                    stroke-linecap="round"
                    :stroke-dasharray="`${segment.percentage} ${circumference - segment.percentage}`"
                    :stroke-dashoffset="-segment.offset"
                    class="transition-all duration-700"
                />
            </svg>
            <div class="absolute inset-0 grid place-items-center text-center">
                <div>
                    <p class="text-2xl font-semibold text-slate-950">{{ total }}</p>
                    <p class="text-xs font-medium text-slate-500">devices</p>
                </div>
            </div>
        </div>

        <div class="space-y-3">
            <div
                v-for="segment in segments"
                :key="segment.label"
                class="flex items-center justify-between gap-3"
            >
                <div class="flex items-center gap-2">
                    <span class="size-2.5 rounded-full" :style="{ backgroundColor: segment.color }" />
                    <span class="text-sm font-medium text-slate-700">{{ segment.label }}</span>
                </div>
                <span class="text-sm font-semibold text-slate-950">{{ segment.value }}</span>
            </div>
        </div>
    </div>
</template>
