<script setup lang="ts">
/**
 * Read-only route map for a device's daily GPS history (Leaflet +
 * OpenStreetMap, no API key). Draws a polyline through the day's pings
 * with a green start + red end marker; falls back to a single marker on
 * the device's last known location, or a default Muscat view when there
 * is nothing to show. Mirrors MapPicker.vue's Leaflet conventions.
 */
import 'leaflet/dist/leaflet.css';
import L, { type LatLngExpression, type LayerGroup, type Map as LeafletMap } from 'leaflet';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { ScalefusionLocationPoint } from '@/lib/api/devices';

const props = withDefaults(defineProps<{
    points: ScalefusionLocationPoint[];
    lat?: number | null;
    lng?: number | null;
    height?: string;
}>(), {
    lat: null,
    lng: null,
    height: '360px',
});

const DEFAULT_CENTER: LatLngExpression = [23.5859, 58.4059]; // Muscat, Oman
const container = ref<HTMLDivElement | null>(null);
let map: LeafletMap | null = null;
let layer: LayerGroup | null = null;

function validPoints(): Array<{ lat: number; lng: number; point: ScalefusionLocationPoint }> {
    return props.points
        .filter((p) => typeof p.latitude === 'number' && typeof p.longitude === 'number')
        .map((p) => ({ lat: p.latitude as number, lng: p.longitude as number, point: p }));
}

function draw(): void {
    if (!map || !layer) return;
    layer.clearLayers();

    const points = validPoints();
    if (points.length > 0) {
        const coords: LatLngExpression[] = points.map((p) => [p.lat, p.lng]);
        L.polyline(coords, { color: '#2563eb', weight: 3, opacity: 0.8 }).addTo(layer);
        points.forEach((p, i) => {
            const isStart = i === 0;
            const isEnd = i === points.length - 1;
            const color = isStart ? '#16a34a' : isEnd ? '#dc2626' : '#3b82f6';
            const marker = L.circleMarker([p.lat, p.lng], {
                radius: isStart || isEnd ? 8 : 5,
                color: '#ffffff', weight: 2, fillColor: color, fillOpacity: 1,
            }).addTo(layer as LayerGroup);
            if (p.point.address) marker.bindPopup(p.point.address);
        });
        map.fitBounds(L.latLngBounds(coords), { padding: [30, 30], maxZoom: 16 });
        return;
    }

    if (typeof props.lat === 'number' && typeof props.lng === 'number') {
        L.circleMarker([props.lat, props.lng], { radius: 8, color: '#ffffff', weight: 2, fillColor: '#2563eb', fillOpacity: 1 }).addTo(layer);
        map.setView([props.lat, props.lng], 14);
        return;
    }

    map.setView(DEFAULT_CENTER, 11);
}

onMounted(() => {
    if (!container.value) return;
    map = L.map(container.value).setView(DEFAULT_CENTER, 11);
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);
    layer = L.layerGroup().addTo(map);
    draw();
});

onBeforeUnmount(() => {
    map?.remove();
    map = null;
    layer = null;
});

watch(() => props.points, () => draw(), { deep: true });
watch(() => [props.lat, props.lng], () => draw());
</script>

<template>
    <div ref="container" class="w-full overflow-hidden rounded-xl border border-slate-200 shadow-sm" :style="{ height }" />
</template>
