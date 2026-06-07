<script setup lang="ts">
/**
 * Lat/lng picker on Google Maps. Drag the marker (or click the map) to
 * choose coordinates; emits `update:modelValue` with { latitude,
 * longitude }. A circle shows the geo-fence radius the branch enforces
 * (blueprint section 9.4).
 *
 * Browser key from VITE_GOOGLE_MAPS_API_KEY (pos_admin/src/.env) -- a
 * public, referrer-restricted key. Same props/emits as before, so
 * BranchFormModal is unaffected.
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { googleMapsApiKey, loadGoogleMaps } from '@/lib/googleMaps';

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

const { t } = useI18n();
const container = ref<HTMLDivElement | null>(null);
const keyMissing = ref(!googleMapsApiKey());

let map: google.maps.Map | null = null;
let marker: google.maps.Marker | null = null;
let circle: google.maps.Circle | null = null;

function currentCenter(): google.maps.LatLngLiteral {
    return {
        lat: props.modelValue.latitude ?? props.defaultCenter.latitude,
        lng: props.modelValue.longitude ?? props.defaultCenter.longitude,
    };
}

function place(lat: number, lng: number): void {
    marker?.setPosition({ lat, lng });
    circle?.setCenter({ lat, lng });
    emit('update:modelValue', { latitude: lat, longitude: lng });
}

onMounted(async () => {
    if (keyMissing.value || !container.value) return;
    const g = await loadGoogleMaps();
    if (!container.value) return;
    const center = currentCenter();
    map = new g.maps.Map(container.value, {
        center, zoom: 14,
        mapTypeControl: false, streetViewControl: false, fullscreenControl: false,
    });
    marker = new g.maps.Marker({ position: center, map, draggable: true });
    circle = new g.maps.Circle({
        center, radius: props.radiusMeters, map,
        strokeColor: '#0d9488', strokeWeight: 1.5, strokeOpacity: 0.9, fillColor: '#0d9488', fillOpacity: 0.08,
    });

    marker.addListener('dragend', () => {
        const pos = marker?.getPosition();
        if (pos) place(pos.lat(), pos.lng());
    });
    map.addListener('click', (event: google.maps.MapMouseEvent) => {
        if (event.latLng) place(event.latLng.lat(), event.latLng.lng());
    });
});

onBeforeUnmount(() => {
    marker?.setMap(null);
    circle?.setMap(null);
    map = null;
    marker = null;
    circle = null;
});

watch(
    () => [props.modelValue.latitude, props.modelValue.longitude] as const,
    ([lat, lng]) => {
        if (!map || !marker || !circle || lat === null || lng === null) return;
        marker.setPosition({ lat, lng });
        circle.setCenter({ lat, lng });
        map.panTo({ lat, lng });
    },
);

watch(() => props.radiusMeters, (radius) => circle?.setRadius(radius));
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
