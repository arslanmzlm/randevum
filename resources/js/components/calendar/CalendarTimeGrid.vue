<script setup lang="ts">
import { computed } from 'vue';
import CalendarTimeColumn from '@/components/calendar/CalendarTimeColumn.vue';
import type { CalendarColumn, CalendarEventDto } from '@/types/calendar';
import { hourMarks, minutesToLabel } from '@/utils/calendarLayout';
import { toDateString } from '@/utils/datetime';

// The day/week time grid: a sticky header row + a scrollable body of equal-width columns sharing a
// time axis. Week = one column per open weekday; day (all doctors) = one column per doctor. The
// same CalendarTimeColumn primitive draws either — only the column data differs.
const props = defineProps<{
    columns: CalendarColumn[];
    fromMinutes: number;
    toMinutes: number;
    /** Vertical scale: the body stretches to fill, then scrolls once rows hit their minimum. */
    pxPerMinute: number;
    /** Clinic-local minute-of-day for the now-line. */
    nowMinutes: number;
}>();

const emit = defineEmits<{
    select: [domEvent: MouseEvent, appointment: CalendarEventDto];
}>();

// A lone, unlabelled day column (single doctor / no all-access) needs no header row.
const showHeader = computed(
    () => props.columns.length > 1 || Boolean(props.columns[0]?.label),
);

const totalHeight = computed(
    () => (props.toMinutes - props.fromMinutes) * props.pxPerMinute,
);

const marks = computed(() =>
    hourMarks(props.fromMinutes, props.toMinutes).filter(
        (m) => m >= props.fromMinutes && m <= props.toMinutes,
    ),
);

function markTop(minutes: number): number {
    return (minutes - props.fromMinutes) * props.pxPerMinute;
}
</script>

<template>
    <div class="flex h-full flex-col">
        <div
            v-if="showHeader"
            class="flex shrink-0 border-b border-surface-200"
        >
            <div class="w-14 shrink-0" />
            <div
                v-for="col in columns"
                :key="col.key"
                class="flex min-w-0 flex-1 items-center justify-center gap-1.5 border-l border-surface-200 px-1 py-2"
            >
                <span
                    v-if="col.tintColor"
                    class="size-2.5 shrink-0 rounded-full"
                    :style="{ backgroundColor: col.tintColor }"
                    aria-hidden="true"
                />
                <span
                    class="truncate text-sm font-medium"
                    :class="
                        col.isToday ? 'text-primary-600' : 'text-surface-600'
                    "
                >
                    {{ col.label }}
                </span>
                <span
                    v-if="col.sublabel"
                    class="text-sm font-semibold tabular-nums"
                    :class="
                        col.isToday ? 'text-primary-600' : 'text-surface-900'
                    "
                >
                    {{ col.sublabel }}
                </span>
            </div>
        </div>

        <!-- Top/bottom padding so the first/last hour label (centred on its grid line) isn't clipped
             by the scroll edge. -->
        <div class="min-h-0 flex-1 overflow-y-auto pt-2 pb-3">
            <div class="flex" :style="{ height: `${totalHeight}px` }">
                <div class="relative w-14 shrink-0">
                    <div
                        v-for="mark in marks"
                        :key="mark"
                        class="absolute right-1.5 -translate-y-1/2 text-[0.65rem] text-surface-400 tabular-nums"
                        :style="{ top: `${markTop(mark)}px` }"
                    >
                        {{ minutesToLabel(mark) }}
                    </div>
                </div>

                <div
                    v-for="col in columns"
                    :key="col.key"
                    class="relative min-w-0 flex-1 border-l border-surface-200"
                    :class="{ 'bg-primary-500/[0.03]': col.isToday }"
                >
                    <div
                        v-for="mark in marks"
                        :key="mark"
                        class="pointer-events-none absolute inset-x-0 border-t border-surface-100"
                        :style="{ top: `${markTop(mark)}px` }"
                    />

                    <CalendarTimeColumn
                        :date="toDateString(col.date)"
                        :events="col.events"
                        :exceptions="col.exceptions"
                        :from-minutes="fromMinutes"
                        :to-minutes="toMinutes"
                        :px-per-minute="pxPerMinute"
                        :closed-bands="col.closedBands"
                        :is-today="col.isToday"
                        :now-minutes="nowMinutes"
                        :named-leave="col.namedLeave"
                        @select="(e, a) => emit('select', e, a)"
                    />
                </div>
            </div>
        </div>
    </div>
</template>
