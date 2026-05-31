<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { IconAlertTriangle } from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import ButtonLink from '@/components/ButtonLink.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';

defineOptions({ layout: AppLayout });

const props = defineProps<{ status: number }>();

const { t, te } = useI18n();

// Fall back to a generic message for any status without its own lang entry.
const key = computed(() =>
    te(`error.${props.status}.title`) ? String(props.status) : 'default',
);
</script>

<template>
    <div
        class="flex min-h-[60vh] flex-col items-center justify-center gap-4 text-center"
    >
        <Head :title="t(`error.${key}.title`)" />

        <IconAlertTriangle class="size-12 text-surface-400" />
        <p class="text-6xl font-bold text-surface-300">{{ status }}</p>
        <h1 class="text-xl font-semibold text-surface-900">
            {{ t(`error.${key}.title`) }}
        </h1>
        <p class="max-w-md text-sm text-surface-500">
            {{ t(`error.${key}.message`) }}
        </p>

        <ButtonLink
            :href="dashboard().url"
            :label="t('error.back_home')"
            class="mt-2"
        />
    </div>
</template>
