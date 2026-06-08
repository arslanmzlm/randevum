<script setup lang="ts">
import {
    IconBriefcase,
    IconClockHour4,
    IconStethoscope,
    IconTag,
    IconUser,
    IconWalk,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppointmentStatusTag from '@/components/AppointmentStatusTag.vue';
import { useDateTime } from '@/composables/useDateTime';
import type { CalendarEventDto } from '@/types/calendar';

// Read-only summary of a clicked appointment, anchored to its chip. No edit/cancel actions yet —
// the detail page (1.7) wires up later. Exposes show()/hide() so the page drives it imperatively.
const { t } = useI18n();
const { formatRange } = useDateTime();

const popover = ref();
const appointment = ref<CalendarEventDto | null>(null);

function show(event: Event, value: CalendarEventDto): void {
    appointment.value = value;
    popover.value?.show(event);
}

function hide(): void {
    popover.value?.hide();
}

defineExpose({ show, hide });

const timeLabel = computed(() =>
    appointment.value
        ? formatRange(appointment.value.start, appointment.value.end)
        : '',
);

const rows = computed(() => {
    const a = appointment.value;

    if (!a) {
        return [];
    }

    return [
        {
            icon: IconStethoscope,
            label: t('calendar.popover.doctor'),
            value: a.doctor_name,
        },
        {
            icon: IconClockHour4,
            label: t('calendar.popover.time'),
            value: timeLabel.value,
        },
        {
            icon: IconBriefcase,
            label: t('calendar.popover.service'),
            value: a.service_name,
        },
        {
            icon: IconTag,
            label: t('calendar.popover.type'),
            value: a.type_name,
            color: a.type_color,
        },
    ].filter((row) => row.value);
});
</script>

<template>
    <Popover ref="popover">
        <div v-if="appointment" class="flex w-72 flex-col gap-3">
            <header class="flex flex-col gap-2">
                <div class="flex min-w-0 items-center gap-2">
                    <IconUser class="size-5 shrink-0 text-surface-400" />
                    <span class="truncate font-semibold text-surface-900">
                        {{ appointment.title }}
                    </span>
                </div>
                <!-- Status (+ walk-in) on their own row so a long label can't squeeze the name. -->
                <div class="flex flex-wrap items-center gap-2">
                    <AppointmentStatusTag :status="appointment.status" />
                    <Tag v-if="appointment.is_walk_in" severity="warn">
                        <template #icon>
                            <IconWalk class="size-3.5" />
                        </template>
                        {{ t('calendar.walk_in') }}
                    </Tag>
                </div>
            </header>

            <dl class="flex flex-col gap-2 text-sm">
                <div
                    v-for="row in rows"
                    :key="row.label"
                    class="flex items-center gap-2"
                >
                    <component
                        :is="row.icon"
                        class="size-4 shrink-0 text-surface-400"
                    />
                    <dt class="shrink-0 text-surface-500">{{ row.label }}:</dt>
                    <dd
                        class="flex min-w-0 items-center gap-1.5 font-medium text-surface-800"
                    >
                        <span
                            v-if="row.color"
                            class="size-2.5 shrink-0 rounded-full"
                            :style="{ backgroundColor: row.color }"
                            aria-hidden="true"
                        />
                        <span class="truncate">{{ row.value }}</span>
                    </dd>
                </div>
            </dl>
        </div>
    </Popover>
</template>
