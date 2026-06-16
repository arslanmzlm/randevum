<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconRefresh } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import UpcomingAppointmentsList from '@/components/appointments/UpcomingAppointmentsList.vue';
import DashboardPanel from '@/components/dashboard/DashboardPanel.vue';
import { useUpcomingAppointments } from '@/composables/useUpcomingAppointments';
import { index as appointmentsIndex } from '@/routes/appointments';

// Self-contained card (title + refresh + list + view-all). Two shells:
// - 'panel'   → the shared DashboardPanel, so it matches the other dashboard cards.
// - 'compact' → the tighter surface-50 card used in the quick-access sidebar.
withDefaults(defineProps<{ variant?: 'panel' | 'compact' }>(), {
    variant: 'panel',
});

const { t } = useI18n();
const { appointments, loading, refresh } = useUpcomingAppointments();
</script>

<template>
    <DashboardPanel v-if="variant === 'panel'">
        <template #title>
            <h2 class="text-base font-semibold text-surface-900">
                {{ t('upcoming_appointments.title') }}
            </h2>
        </template>

        <template #actions>
            <button
                type="button"
                class="flex cursor-pointer items-center rounded-lg p-1 text-surface-400 transition-colors hover:bg-surface-100 hover:text-surface-600"
                :aria-label="t('upcoming_appointments.refresh')"
                @click="refresh"
            >
                <IconRefresh
                    class="size-4"
                    :class="{ 'animate-spin': loading }"
                />
            </button>
        </template>

        <div class="max-h-96 overflow-y-auto">
            <UpcomingAppointmentsList
                :appointments="appointments"
                :loading="loading"
            />
        </div>

        <template v-if="appointments.length" #footer>
            <Link
                :href="appointmentsIndex().url"
                class="block text-center text-xs font-medium text-primary-600 hover:text-primary-700"
            >
                {{ t('upcoming_appointments.view_all') }}
            </Link>
        </template>
    </DashboardPanel>

    <section
        v-else
        class="flex flex-col overflow-hidden rounded-2xl border border-surface-300 bg-surface-50"
    >
        <header class="flex items-center justify-between gap-2 px-3 py-2">
            <h3 class="text-sm font-semibold text-surface-900">
                {{ t('upcoming_appointments.title') }}
            </h3>
            <button
                type="button"
                class="flex cursor-pointer items-center rounded-lg p-1 text-surface-400 transition-colors hover:bg-surface-100 hover:text-surface-600"
                :aria-label="t('upcoming_appointments.refresh')"
                @click="refresh"
            >
                <IconRefresh
                    class="size-4"
                    :class="{ 'animate-spin': loading }"
                />
            </button>
        </header>

        <div class="max-h-96 overflow-y-auto border-t border-surface-200">
            <UpcomingAppointmentsList
                :appointments="appointments"
                :loading="loading"
            />
        </div>

        <footer
            v-if="appointments.length"
            class="border-t border-surface-200 px-3 py-2"
        >
            <Link
                :href="appointmentsIndex().url"
                class="block text-center text-xs font-medium text-primary-600 hover:text-primary-700"
            >
                {{ t('upcoming_appointments.view_all') }}
            </Link>
        </footer>
    </section>
</template>
