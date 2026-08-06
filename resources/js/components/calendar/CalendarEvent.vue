<script setup lang="ts">
import { IconWalk } from '@tabler/icons-vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { CalendarEventDto } from '@/types/calendar';
import { appointmentStatusColor } from '@/utils/appointmentStatus';

// The appointment chip inside a time-grid column. The STATUS drives the colour (left bar + faint
// fill) so confirmed/arrived/completed/cancelled read apart at a glance; the appointment TYPE shows
// as a small dot. Walk-in adds a yellow top accent, cancelled is muted + struck through. Short
// events collapse to a single line (time + name) so a packed column doesn't read as clutter; taller
// ones show the full time range + type. Clicking emits the DOM event for the read-only popover.
const props = defineProps<{
    appointment: CalendarEventDto;
    /** Rendered pixel height — drives the compact↔full density switch. */
    heightPx?: number;
    /**
     * Doctor accent for a column that mixes doctors (week view): takes over the left bar, so the
     * doctor becomes the chip's identity colour while the status tint/muting stays readable.
     */
    accentColor?: string;
}>();

const emit = defineEmits<{
    select: [event: MouseEvent];
}>();

const { t } = useI18n();

const isCancelled = computed(() => props.appointment.status === 'cancelled');

// Below the height needed for the time-range + name + type stack, drop to one line.
const dense = computed(() => (props.heightPx ?? Infinity) < 50);

// Server start/end are clinic-local 'YYYY-MM-DD HH:mm' — slice the wall-clock time directly.
const startTime = computed(() => props.appointment.start.slice(11, 16));
const endTime = computed(() => props.appointment.end.slice(11, 16));

const statusColor = computed(() =>
    appointmentStatusColor(props.appointment.status),
);

// Explicit, self-contained colours: a faint tint of the status colour over the surface with a solid
// accent bar, so the chip reads correctly in light/dark without depending on any ancestor styling.
const chipStyle = computed(() => ({
    borderLeftColor: props.accentColor ?? statusColor.value,
    backgroundColor: `color-mix(in srgb, ${statusColor.value} 12%, var(--p-surface-0))`,
}));
</script>

<template>
    <button
        type="button"
        class="calendar-event-chip flex h-full w-full cursor-pointer overflow-hidden rounded-lg border-l-4 px-2 text-left leading-snug text-surface-900 shadow-sm transition-opacity hover:opacity-90"
        :class="[
            dense ? 'items-center py-0.5' : 'flex-col gap-1 py-1',
            isCancelled ? 'opacity-60' : '',
            appointment.is_walk_in ? 'calendar-event-chip--walkin' : '',
        ]"
        :style="chipStyle"
        :title="accentColor ? appointment.doctor_name : undefined"
        @click.stop="emit('select', $event)"
    >
        <!-- Compact: one line — time + patient. -->
        <span
            v-if="dense"
            class="flex w-full items-center gap-1 overflow-hidden text-[0.7rem]"
        >
            <span
                v-if="appointment.type_color"
                class="size-2 shrink-0 rounded-full"
                :style="{ backgroundColor: appointment.type_color }"
                :title="appointment.type_name ?? undefined"
                aria-hidden="true"
            />
            <span class="shrink-0 font-medium tabular-nums opacity-90">
                {{ startTime }}
            </span>
            <span
                class="truncate font-semibold"
                :class="isCancelled ? 'line-through' : ''"
            >
                {{ appointment.title }}
            </span>
            <IconWalk
                v-if="appointment.is_walk_in"
                class="ml-auto size-3.5 shrink-0"
                :aria-label="t('calendar.walk_in')"
            />
        </span>

        <!-- Full: time range + patient + type. -->
        <template v-else>
            <span
                class="flex w-full items-center gap-1 text-[0.7rem] font-medium tabular-nums opacity-90"
            >
                <span
                    v-if="appointment.type_color"
                    class="size-2 shrink-0 rounded-full"
                    :style="{ backgroundColor: appointment.type_color }"
                    :title="appointment.type_name ?? undefined"
                    aria-hidden="true"
                />
                {{ startTime }}–{{ endTime }}
                <IconWalk
                    v-if="appointment.is_walk_in"
                    class="ml-auto size-3.5 shrink-0"
                    :aria-label="t('calendar.walk_in')"
                />
            </span>

            <span
                class="block w-full truncate text-xs font-semibold"
                :class="isCancelled ? 'line-through' : ''"
            >
                {{ appointment.title }}
            </span>

            <span
                v-if="appointment.type_name"
                class="block w-full truncate text-[0.7rem] opacity-80"
            >
                {{ appointment.type_name }}
            </span>
        </template>
    </button>
</template>

<style scoped>
/* Walk-in carries the data-model yellow accent in addition to the type color bar (top edge). */
.calendar-event-chip--walkin {
    box-shadow: inset 0 2px 0 0 var(--p-yellow-400);
}
</style>
