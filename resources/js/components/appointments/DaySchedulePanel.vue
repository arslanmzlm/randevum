<script setup lang="ts">
import { IconCalendarTime, IconLoader2 } from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import AppointmentStatusTag from '@/components/AppointmentStatusTag.vue';
import { useDaySchedule } from '@/composables/useDaySchedule';
import { formatLocalDate } from '@/utils/appointmentTime';

const props = defineProps<{
    doctorId: number | null;
    date: Date | null;
}>();

const { t } = useI18n();

const { state, entries } = useDaySchedule(() => {
    if (!props.doctorId || !props.date) {
        return null;
    }

    return { doctor_id: props.doctorId, date: formatLocalDate(props.date) };
});
</script>

<template>
    <section
        v-if="doctorId && date"
        class="rounded-xl border border-surface-200 bg-surface-0 p-6"
    >
        <header class="mb-4 flex items-center gap-2">
            <IconCalendarTime class="size-5 text-surface-500" />
            <h2 class="text-base font-semibold text-surface-900">
                {{ t('appointment.day_schedule.title') }}
            </h2>
        </header>

        <div
            v-if="state === 'loading'"
            class="flex items-center gap-2 text-sm text-surface-500"
        >
            <IconLoader2 class="size-4 animate-spin" />
            {{ t('appointment.day_schedule.loading') }}
        </div>
        <p v-else-if="entries.length === 0" class="text-sm text-surface-500">
            {{ t('appointment.day_schedule.empty') }}
        </p>
        <ul
            v-else
            class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
            <li
                v-for="entry in entries"
                :key="entry.id"
                class="flex items-center justify-between gap-3 rounded-lg border border-surface-200 p-3"
            >
                <div class="flex min-w-0 flex-col">
                    <span class="text-sm font-medium text-surface-900">
                        {{ entry.start_time }}–{{ entry.end_time }}
                    </span>
                    <span class="truncate text-xs text-surface-500">
                        {{ entry.patient_name }}
                    </span>
                    <span
                        v-if="entry.service_name"
                        class="truncate text-xs text-surface-400"
                    >
                        {{ entry.service_name }}
                    </span>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <Tag
                        v-if="entry.is_walk_in"
                        :value="t('appointment.day_schedule.walk_in')"
                        severity="secondary"
                    />
                    <AppointmentStatusTag :status="entry.status" />
                </div>
            </li>
        </ul>
    </section>
</template>
