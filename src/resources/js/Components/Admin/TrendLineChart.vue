<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    points: number[];
    labels: string[];
}>();

const pathPoints = computed(() => {
    const width = 520;
    const height = 180;
    const maxValue = Math.max(...props.points, 1);
    const minValue = Math.min(...props.points, 0);
    const range = Math.max(maxValue - minValue, 1);
    const step = props.points.length > 1 ? width / (props.points.length - 1) : width;

    return props.points
        .map((point, index) => {
            const x = index * step;
            const y = height - ((point - minValue) / range) * height;

            return `${x.toFixed(2)},${y.toFixed(2)}`;
        })
        .join(' ');
});
</script>

<template>
    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-base font-semibold text-slate-950">Merchant Sales Trend</h2>
                <p class="mt-1 text-sm text-slate-500">Pilot transaction volume across active branches</p>
            </div>
            <div class="flex items-center gap-2 text-sm font-semibold text-emerald-700">
                <span class="size-2 rounded-full bg-emerald-500" />
                +18.4%
            </div>
        </div>

        <div class="mt-6 h-64 min-w-0">
            <svg viewBox="0 0 520 230" class="h-full w-full overflow-visible">
                <defs>
                    <linearGradient id="salesGradient" x1="0" x2="0" y1="0" y2="1">
                        <stop offset="0%" stop-color="#0f766e" stop-opacity="0.22" />
                        <stop offset="100%" stop-color="#0f766e" stop-opacity="0" />
                    </linearGradient>
                </defs>
                <g class="text-slate-200">
                    <line v-for="line in 5" :key="line" x1="0" x2="520" :y1="line * 36" :y2="line * 36" stroke="currentColor" />
                </g>
                <polyline
                    :points="`0,180 ${pathPoints} 520,180`"
                    fill="url(#salesGradient)"
                    stroke="transparent"
                />
                <polyline
                    :points="pathPoints"
                    fill="none"
                    stroke="#0f766e"
                    stroke-width="4"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    class="chart-line"
                />
                <g v-for="(label, index) in labels" :key="label">
                    <text
                        :x="index * (520 / Math.max(labels.length - 1, 1))"
                        y="220"
                        text-anchor="middle"
                        class="fill-slate-400 text-[12px] font-medium"
                    >
                        {{ label }}
                    </text>
                </g>
            </svg>
        </div>
    </div>
</template>

<style scoped>
.chart-line {
    stroke-dasharray: 740;
    stroke-dashoffset: 740;
    animation: draw-line 1.15s ease-out forwards;
}

@keyframes draw-line {
    to {
        stroke-dashoffset: 0;
    }
}
</style>
