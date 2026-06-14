<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconRefresh } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import UpcomingAppointmentsList from '@/components/appointments/UpcomingAppointmentsList.vue';
import { useUpcomingAppointments } from '@/composables/useUpcomingAppointments';
import { index as appointmentsIndex } from '@/routes/appointments';

// Self-contained card (title + refresh + list + view-all). Embedded in the quick-access
// sidebar today; the 1.3 dashboard right-rail can reuse it as-is.
const { t } = useI18n();
const { appointments, loading, refresh } = useUpcomingAppointments();
</script>

<template>
    <section
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
