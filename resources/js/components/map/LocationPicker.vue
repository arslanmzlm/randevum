<script setup lang="ts">
import { IconMapPinOff } from '@tabler/icons-vue';
import { defineAsyncComponent, h, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { MapDefaults, MapPoint } from './types';

// Provider-agnostic: pages only ever talk to this component, never to the map library.
withDefaults(
    defineProps<{
        modelValue: MapPoint | null;
        defaults: MapDefaults;
        readonly?: boolean;
        error?: string;
    }>(),
    { readonly: false, error: undefined },
);

const emit = defineEmits<{
    'update:modelValue': [MapPoint | null];
}>();

const { t } = useI18n();

// Leaflet is heavy and touches `window` on import: keep it out of the initial chunk (the page must
// not wait on the map) and out of any SSR render pass.
const MapCanvas = defineAsyncComponent({
    loader: () => import('./LeafletMapCanvas.vue'),
    loadingComponent: () =>
        h('div', {
            class: 'size-full animate-pulse bg-surface-100',
            role: 'status',
            'aria-label': t('clinic.map.loading'),
        }),
});

const isMounted = ref(false);

onMounted(() => {
    isMounted.value = true;
});
</script>

<template>
    <div class="flex flex-col gap-2">
        <div
            class="h-80 w-full overflow-hidden rounded-xl border border-surface-200 bg-surface-100"
        >
            <MapCanvas
                v-if="isMounted"
                :model-value="modelValue"
                :defaults="defaults"
                :readonly="readonly"
                @update:model-value="emit('update:modelValue', $event)"
            />
        </div>

        <div class="flex items-center justify-between gap-2">
            <p class="text-sm text-surface-600">
                {{
                    modelValue
                        ? t('clinic.map.selected', {
                              lat: modelValue.lat.toFixed(6),
                              lng: modelValue.lng.toFixed(6),
                          })
                        : t('clinic.map.empty')
                }}
            </p>

            <Button
                v-if="modelValue && !readonly"
                type="button"
                text
                size="small"
                severity="secondary"
                @click="emit('update:modelValue', null)"
            >
                <IconMapPinOff />
                {{ t('clinic.map.clear') }}
            </Button>
        </div>

        <small v-if="error" class="text-xs text-red-500">{{ error }}</small>
    </div>
</template>
