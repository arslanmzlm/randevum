<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import AppToaster from '@/components/AppToaster.vue';

const { t } = useI18n();
</script>

<template>
    <div class="flex min-h-screen flex-col lg:flex-row">
        <AppToaster />
        <div
            class="relative hidden shrink-0 items-center justify-center overflow-hidden bg-brand lg:flex lg:w-2/5 lg:flex-col xl:w-[42%]"
            aria-hidden="true"
        >
            <div
                class="pointer-events-none absolute -top-24 -left-24 h-96 w-96 rounded-full bg-white/10"
            />
            <div
                class="pointer-events-none absolute right-0 bottom-0 h-72 w-72 translate-x-1/3 translate-y-1/3 rounded-full bg-white/10"
            />

            <div class="relative z-10 px-8 text-center select-none">
                <p class="text-4xl font-semibold tracking-tight text-white">
                    {{ t('auth.layout.brand') }}
                </p>
                <p class="mt-3 text-sm text-white/70">
                    {{ t('auth.layout.tagline') }}
                </p>
            </div>
        </div>

        <div
            class="flex flex-1 items-center justify-center bg-white p-6 lg:p-16"
        >
            <div class="w-full max-w-md">
                <slot />
            </div>
        </div>
    </div>
</template>
