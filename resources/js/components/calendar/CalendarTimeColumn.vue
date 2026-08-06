<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import CalendarEvent from '@/components/calendar/CalendarEvent.vue';
import type {
    CalendarClosedBand,
    CalendarEventDto,
    CalendarExceptionDto,
} from '@/types/calendar';
import {
    layoutBlocks,
    wallClockDate,
    wallClockMinutes,
} from '@/utils/calendarLayout';

// One day/doctor column: appointment chips positioned by minute offset (overlaps split into
// side-by-side lanes), over muted backdrop bands for closed/break hours and per-doctor leave.
const props = defineProps<{
    /** This column's clinic-local date ('YYYY-MM-DD') — clamps multi-day leave to the window. */
    date: string;
    events: CalendarEventDto[];
    exceptions: CalendarExceptionDto[];
    fromMinutes: number;
    toMinutes: number;
    /** Vertical scale — used to derive each event's pixel height for the density switch. */
    pxPerMinute: number;
    closedBands: CalendarClosedBand[];
    isToday?: boolean;
    /** Clinic-local minute-of-day for the now-line; only drawn when isToday and within window. */
    nowMinutes?: number;
    /** Label each leave block with its doctor — a column that mixes doctors needs the name. */
    namedLeave?: boolean;
    /** doctor id → accent colour; set when the column mixes doctors (week view). */
    doctorColors?: Record<number, string>;
}>();

const emit = defineEmits<{
    select: [domEvent: MouseEvent, appointment: CalendarEventDto];
}>();

const { t } = useI18n();

const span = computed(() => Math.max(1, props.toMinutes - props.fromMinutes));

function topPct(minutes: number): number {
    return ((minutes - props.fromMinutes) / span.value) * 100;
}

function bandStyle(fromMin: number, toMin: number): Record<string, string> {
    const from = Math.max(fromMin, props.fromMinutes);
    const to = Math.min(toMin, props.toMinutes);

    return {
        top: `${topPct(from)}%`,
        height: `${topPct(to) - topPct(from)}%`,
    };
}

const eventBlocks = computed(() =>
    layoutBlocks(
        props.events,
        (a) => ({
            startMin: wallClockMinutes(a.start),
            endMin: wallClockMinutes(a.end),
        }),
        props.fromMinutes,
        props.toMinutes,
    ),
);

// Clamp each leave block to this column's date so a multi-day exception fills only its slice, then
// lane them like appointments — two doctors off at the same hour would otherwise cover each other.
const leaveBlocks = computed(() =>
    layoutBlocks(
        props.exceptions,
        (e) => ({
            startMin:
                wallClockDate(e.start) < props.date
                    ? props.fromMinutes
                    : wallClockMinutes(e.start),
            endMin:
                wallClockDate(e.end) > props.date
                    ? props.toMinutes
                    : wallClockMinutes(e.end),
        }),
        props.fromMinutes,
        props.toMinutes,
    ).map((block) => ({
        key: `exc-${block.item.id}`,
        doctorName: props.namedLeave ? block.item.doctor_name : null,
        reason: block.item.reason ?? t('calendar.closed'),
        style: {
            top: `${block.topPct}%`,
            height: `${block.heightPct}%`,
            left: `${(block.lane * 100) / block.lanes}%`,
            width: `${100 / block.lanes}%`,
        },
    })),
);

const nowLineTop = computed(() => {
    if (
        !props.isToday ||
        props.nowMinutes === undefined ||
        props.nowMinutes < props.fromMinutes ||
        props.nowMinutes > props.toMinutes
    ) {
        return null;
    }

    return `${topPct(props.nowMinutes)}%`;
});
</script>

<template>
    <div class="absolute inset-0">
        <!-- Closed / break backdrop — non-interactive, muted, label centred. -->
        <div
            v-for="(band, i) in closedBands"
            :key="`band-${i}`"
            class="pointer-events-none absolute inset-x-0 flex items-center justify-center bg-surface-100/60"
            :style="bandStyle(band.fromMin, band.toMin)"
        >
            <span
                v-if="band.label"
                class="text-xs font-medium text-surface-500"
            >
                {{ band.label }}
            </span>
        </div>

        <!-- Per-doctor leave — hatched backdrop, label centred. -->
        <div
            v-for="block in leaveBlocks"
            :key="block.key"
            class="calendar-leave pointer-events-none absolute flex items-center justify-center gap-1 overflow-hidden px-1 text-xs font-medium"
            :style="block.style"
        >
            <span
                v-if="block.doctorName"
                class="max-w-full shrink-0 truncate text-surface-700"
            >
                {{ block.doctorName }}
            </span>
            <span
                class="truncate"
                :class="
                    block.doctorName ? 'text-surface-500' : 'text-surface-600'
                "
            >
                {{ block.doctorName ? '· ' : '' }}{{ block.reason }}
            </span>
        </div>

        <!-- Appointments. -->
        <div
            v-for="block in eventBlocks"
            :key="block.item.id"
            class="absolute"
            :style="{
                top: `${block.topPct}%`,
                minHeight: '1.4rem',
                // 4px shorter than the true slot height leaves a gap between back-to-back events.
                height: `calc(${block.heightPct}% - 4px)`,
                left: `calc(${(block.lane * 100) / block.lanes}% + 2px)`,
                width: `calc(${100 / block.lanes}% - 4px)`,
            }"
        >
            <CalendarEvent
                :appointment="block.item"
                :height-px="(block.endMin - block.startMin) * pxPerMinute"
                :accent-color="doctorColors?.[block.item.doctor_id]"
                @select="emit('select', $event, block.item)"
            />
        </div>

        <!-- Now line. -->
        <div
            v-if="nowLineTop !== null"
            class="pointer-events-none absolute inset-x-0 z-20 border-t-2 border-primary-500"
            :style="{ top: nowLineTop }"
        >
            <span
                class="absolute -top-1 -left-1 size-2 rounded-full bg-primary-500"
                aria-hidden="true"
            />
        </div>
    </div>
</template>

<style scoped>
/* Leave blocks read as a hatched, non-interactive backdrop distinct from the solid closed bands. */
.calendar-leave {
    background-color: var(--p-surface-100);
    background-image: repeating-linear-gradient(
        45deg,
        transparent,
        transparent 6px,
        color-mix(in srgb, var(--p-surface-300) 50%, transparent) 6px,
        color-mix(in srgb, var(--p-surface-300) 50%, transparent) 12px
    );
}
</style>
