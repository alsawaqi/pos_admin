<script setup lang="ts">
/**
 * Read-only route map for a device's daily GPS history, on Google Maps.
 * Draws a polyline through the day's pings with a green start + red end
 * marker; falls back to the device's last known location, then a default
 * Muscat view. Browser key from VITE_GOOGLE_MAPS_API_KEY.
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ScalefusionLocationPoint } from '@/lib/api/devices';
import { googleMapsApiKey, loadGoogleMaps } from '@/lib/googleMaps';

const props = withDefaults(defineProps<{
    points: ScalefusionLocationPoint[];
    lat?: number | null;
    lng?: number | null;
    height?: string;
}>(), { lat: null, lng: null, height: '360px' });

const { t } = useI18n();
const DEFAULT_CENTER: google.maps.LatLngLiteral = { lat: 23.5859, lng: 58.4059 }; // Muscat, Oman
const container = ref<HTMLDivElement | null>(null);
const keyMissing = ref(!googleMapsApiKey());

let map: google.maps.Map | null = null;
let overlays: Array<google.maps.Marker | google.maps.Polyline> = [];

function clearOverlays(): void {
    overlays.forEach((o) => o.setMap(null));
    overlays = [];
}

function dot(g: typeof google, position: google.maps.LatLngLiteral, color: string, scale: number, title?: string): google.maps.Marker {
    return new g.maps.Marker({
        position,
        map,
        title,
        icon: { path: g.maps.SymbolPath.CIRCLE, scale, fillColor: color, fillOpacity: 1, strokeColor: '#ffffff', strokeWeight: 2 },
    });
}

async function draw(): Promise<void> {
    if (!map) return;
    const g = await loadGoogleMaps();
    clearOverlays();

    const pts = props.points
        .filter((p) => typeof p.latitude === 'number' && typeof p.longitude === 'number')
        .map((p) => ({ lat: p.latitude as number, lng: p.longitude as number, address: p.address }));

    if (pts.length > 0) {
        const path = pts.map((p) => ({ lat: p.lat, lng: p.lng }));
        overlays.push(new g.maps.Polyline({ path, strokeColor: '#2563eb', strokeWeight: 3, strokeOpacity: 0.8, map }));
        const bounds = new g.maps.LatLngBounds();
        pts.forEach((p, i) => {
            const isStart = i === 0;
            const isEnd = i === pts.length - 1;
            const color = isStart ? '#16a34a' : isEnd ? '#dc2626' : '#3b82f6';
            const marker = dot(g, { lat: p.lat, lng: p.lng }, color, isStart || isEnd ? 7 : 5, p.address ?? undefined);
            if (p.address) {
                const info = new g.maps.InfoWindow({ content: p.address });
                marker.addListener('click', () => info.open({ map: map as google.maps.Map, anchor: marker }));
            }
            overlays.push(marker);
            bounds.extend({ lat: p.lat, lng: p.lng });
        });
        map.fitBounds(bounds, 30);
        return;
    }

    if (typeof props.lat === 'number' && typeof props.lng === 'number') {
        overlays.push(dot(g, { lat: props.lat, lng: props.lng }, '#2563eb', 7));
        map.setCenter({ lat: props.lat, lng: props.lng });
        map.setZoom(14);
        return;
    }

    map.setCenter(DEFAULT_CENTER);
    map.setZoom(11);
}

onMounted(async () => {
    if (keyMissing.value || !container.value) return;
    const g = await loadGoogleMaps();
    if (!container.value) return;
    map = new g.maps.Map(container.value, {
        center: DEFAULT_CENTER, zoom: 11,
        mapTypeControl: false, streetViewControl: false, fullscreenControl: false,
    });
    await draw();
});

onBeforeUnmount(() => {
    clearOverlays();
    map = null;
});

watch(() => props.points, () => void draw(), { deep: true });
watch(() => [props.lat, props.lng], () => void draw());
</script>

<template>
    <div
        v-if="keyMissing"
        class="grid place-items-center rounded-xl border border-amber-200 bg-amber-50 px-4 text-center text-sm font-semibold text-amber-800"
        :style="{ height }"
    >
        {{ t('common.map_key_missing') }}
    </div>
    <div v-else ref="container" class="w-full overflow-hidden rounded-xl border border-slate-200 shadow-sm" :style="{ height }" />
</template>
