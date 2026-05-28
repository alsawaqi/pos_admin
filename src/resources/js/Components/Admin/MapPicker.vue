<script setup lang="ts">
/**
 * Lat/lng picker built on Leaflet + OpenStreetMap tiles.
 *
 * Drag the marker (or click the map) to choose coordinates; emits
 * `update:modelValue` with `{ latitude, longitude }` whenever the
 * position changes. A second optional ring shows the geo-fence radius
 * the branch will enforce on every POS request (blueprint §9.4).
 *
 * No API key required — OSM tiles are free for low/moderate use, which
 * matches MVP traffic. Swap the tile URL later if usage grows.
 */
import 'leaflet/dist/leaflet.css';
import L, { type LatLngExpression, type Map as LeafletMap, type Marker, type Circle } from 'leaflet';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = withDefaults(defineProps<{
    modelValue: { latitude: number | null; longitude: number | null };
    radiusMeters?: number;
    defaultCenter?: { latitude: number; longitude: number };
    height?: string;
}>(), {
    radiusMeters: 500,
    defaultCenter: () => ({ latitude: 23.5859, longitude: 58.4059 }), // Muscat, Oman
    height: '360px',
});

const emit = defineEmits<{
    (e: 'update:modelValue', value: { latitude: number; longitude: number }): void;
}>();

const container = ref<HTMLDivElement | null>(null);
let map: LeafletMap | null = null;
let marker: Marker | null = null;
let circle: Circle | null = null;

// Default Leaflet markers reference external image paths that 404 when
// bundled by Vite. Inline a CDN icon URL so the marker always renders.
const markerIcon = L.icon({
    iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
    iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
    shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
    iconSize: [25, 41],
    iconAnchor: [12, 41],
    popupAnchor: [1, -34],
    shadowSize: [41, 41],
});

function currentCenter(): LatLngExpression {
    const lat = props.modelValue.latitude ?? props.defaultCenter.latitude;
    const lng = props.modelValue.longitude ?? props.defaultCenter.longitude;

    return [lat, lng];
}

function broadcast(lat: number, lng: number): void {
    emit('update:modelValue', { latitude: lat, longitude: lng });
}

onMounted(() => {
    if (!container.value) {
        return;
    }

    map = L.map(container.value).setView(currentCenter(), 14);

    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);

    marker = L.marker(currentCenter(), { draggable: true, icon: markerIcon }).addTo(map);
    circle = L.circle(currentCenter(), {
        radius: props.radiusMeters,
        color: '#0d9488',
        weight: 1.5,
        opacity: 0.9,
        fillOpacity: 0.08,
    }).addTo(map);

    marker.on('dragend', () => {
        if (!marker || !circle) {
            return;
        }
        const { lat, lng } = marker.getLatLng();
        circle.setLatLng([lat, lng]);
        broadcast(lat, lng);
    });

    map.on('click', (event) => {
        if (!marker || !circle) {
            return;
        }
        marker.setLatLng(event.latlng);
        circle.setLatLng(event.latlng);
        broadcast(event.latlng.lat, event.latlng.lng);
    });
});

onBeforeUnmount(() => {
    map?.remove();
    map = null;
    marker = null;
    circle = null;
});

// External updates (e.g. user edits the lat/lng numeric inputs directly).
watch(
    () => [props.modelValue.latitude, props.modelValue.longitude] as const,
    ([lat, lng]) => {
        if (!map || !marker || !circle || lat === null || lng === null) {
            return;
        }
        const center: LatLngExpression = [lat, lng];
        marker.setLatLng(center);
        circle.setLatLng(center);
        map.panTo(center);
    },
);

watch(
    () => props.radiusMeters,
    (radius) => {
        circle?.setRadius(radius);
    },
);
</script>

<template>
    <div
        ref="container"
        class="w-full overflow-hidden rounded-xl border border-slate-200 shadow-sm"
        :style="{ height }"
    />
</template>
