<script setup lang="ts">
import * as L from 'leaflet';
import markerIconRetinaUrl from 'leaflet/dist/images/marker-icon-2x.png';
import markerIconUrl from 'leaflet/dist/images/marker-icon.png';
import markerShadowUrl from 'leaflet/dist/images/marker-shadow.png';
import 'leaflet/dist/leaflet.css';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { MapDefaults, MapPoint } from './types';

// The ONLY file in the app that may import Leaflet. Swapping to another map provider means
// replacing this file plus the dynamic import in LocationPicker.vue — pages stay untouched.
const props = withDefaults(
    defineProps<{
        modelValue: MapPoint | null;
        defaults: MapDefaults;
        readonly?: boolean;
    }>(),
    { readonly: false },
);

const emit = defineEmits<{
    'update:modelValue': [MapPoint | null];
}>();

const { t } = useI18n();

const host = ref<HTMLElement | null>(null);

let map: L.Map | null = null;
let marker: L.Marker | null = null;
let observer: ResizeObserver | null = null;

// Leaflet's default icon resolves its images from a CDN-ish relative path; pointing at the
// Vite-bundled assets keeps every map asset local.
const pinIcon = L.icon({
    iconUrl: markerIconUrl,
    iconRetinaUrl: markerIconRetinaUrl,
    shadowUrl: markerShadowUrl,
    iconSize: [25, 41],
    iconAnchor: [12, 41],
    shadowSize: [41, 41],
});

function placeMarker(point: MapPoint): void {
    if (!map) {
        return;
    }

    if (marker) {
        marker.setLatLng(point);

        return;
    }

    marker = L.marker(point, {
        icon: pinIcon,
        draggable: !props.readonly,
    }).addTo(map);

    if (!props.readonly) {
        marker.on('dragend', () => {
            // Dragging onto a repeated world copy yields a longitude outside ±180, which the
            // backend rejects — wrap it back onto the real world before emitting.
            const position = marker?.getLatLng().wrap();

            if (position) {
                select({ lat: position.lat, lng: position.lng });
            }
        });
    }
}

function select(point: MapPoint): void {
    placeMarker(point);
    emit('update:modelValue', point);
}

onMounted(() => {
    if (!host.value) {
        return;
    }

    const point = props.modelValue;

    // worldCopyJump keeps the view on the real world when the user pans past a repeated copy,
    // so the marker stays visible after its coordinates are wrapped.
    map = L.map(host.value, { worldCopyJump: true }).setView(
        point
            ? [point.lat, point.lng]
            : [props.defaults.lat, props.defaults.lng],
        point ? props.defaults.selected_zoom : props.defaults.zoom,
    );

    // Attribution is required by the OpenStreetMap tile usage policy — keep the control on.
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: t('clinic.map.attribution'),
    }).addTo(map);

    if (point) {
        placeMarker(point);
    }

    if (!props.readonly) {
        map.on('click', (event: L.LeafletMouseEvent) => {
            const point = event.latlng.wrap();

            select({ lat: point.lat, lng: point.lng });
        });
    }

    // PrimeVue's non-lazy TabPanel keeps inactive panels in the DOM under `v-show`, so the map is
    // first created at 0×0 and would keep rendering grey tiles without this. Also covers the
    // sidebar collapsing and window resizes.
    observer = new ResizeObserver(() => map?.invalidateSize());
    observer.observe(host.value);
});

watch(
    () => props.modelValue,
    (point) => {
        if (!map) {
            return;
        }

        if (!point) {
            marker?.remove();
            marker = null;

            return;
        }

        const current = marker?.getLatLng();

        // Ignore the echo of our own click/drag emit; re-centring there would yank the map
        // out from under the user.
        if (current && current.lat === point.lat && current.lng === point.lng) {
            return;
        }

        placeMarker(point);
        map.setView(point, props.defaults.selected_zoom);
    },
);

onBeforeUnmount(() => {
    observer?.disconnect();
    observer = null;
    map?.remove();
    map = null;
    marker = null;
});
</script>

<template>
    <div ref="host" class="size-full"></div>
</template>
