<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import UpcomingAppointmentsWidget from '@/components/appointments/UpcomingAppointmentsWidget.vue';

// The right-hand quick-access area. Hosts the upcoming-appointments widget today;
// frequently-used actions get added here as separate widgets later.
const { t } = useI18n();
</script>

<template>
    <div class="flex h-full min-h-0 flex-col bg-surface-0">
        <header class="shrink-0 px-5 pt-6 pb-3 lg:pt-8">
            <h2
                class="text-xs font-semibold tracking-wide text-surface-500 uppercase"
            >
                {{ t('quick_access.title') }}
            </h2>
        </header>

        <div class="flex-1 space-y-4 overflow-y-auto px-4 pb-4">
            <UpcomingAppointmentsWidget variant="compact" />
        </div>
    </div>
</template>
