<script setup lang="ts">
import { addDays, startOfMonth, startOfWeek } from 'date-fns';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { CalendarDaySummary } from '@/types/calendar';
import { formatWeekdayShort, toDateString } from '@/utils/datetime';

// Month overview: a 6×7 grid showing ONE chip per doctor per day ("Dr X · N") instead of every
// appointment — a readable summary, not a noisy list. Clicking a day drills into it; clicking a
// doctor chip drills into that day filtered to that doctor.
const props = defineProps<{
    viewDate: Date;
    /** Per-doctor daily summaries keyed by 'YYYY-MM-DD'. */
    summaries: Map<string, CalendarDaySummary[]>;
    /** Clinic-local today ('YYYY-MM-DD') for the today highlight. */
    todayKey: string;
    doctorColor: (doctorId: number) => string;
}>();

const emit = defineEmits<{
    'cell-click': [date: Date];
    'summary-click': [date: string, doctorId: number];
}>();

const { locale } = useI18n();

const MAX_CHIPS = 3;

const weekdayNames = computed(() => {
    const monday = startOfWeek(props.viewDate, { weekStartsOn: 1 });

    return Array.from({ length: 7 }, (_, i) =>
        formatWeekdayShort(addDays(monday, i), locale.value),
    );
});

const weeks = computed(() => {
    const start = startOfWeek(startOfMonth(props.viewDate), {
        weekStartsOn: 1,
    });
    const month = props.viewDate.getMonth();

    return Array.from({ length: 6 }, (_, w) =>
        Array.from({ length: 7 }, (_, d) => {
            const date = addDays(start, w * 7 + d);
            const key = toDateString(date);
            const summaries = props.summaries.get(key) ?? [];

            return {
                date,
                key,
                dayNum: date.getDate(),
                inMonth: date.getMonth() === month,
                isToday: key === props.todayKey,
                visible: summaries.slice(0, MAX_CHIPS),
                overflow: Math.max(0, summaries.length - MAX_CHIPS),
            };
        }),
    );
});
</script>

<template>
    <div class="flex h-full flex-col">
        <div class="grid shrink-0 grid-cols-7 border-b border-surface-200">
            <div
                v-for="name in weekdayNames"
                :key="name"
                class="px-2 py-2 text-center text-xs font-medium text-surface-500"
            >
                {{ name }}
            </div>
        </div>

        <div class="grid min-h-0 flex-1 grid-cols-7 grid-rows-6">
            <!-- The cell click (mouse convenience) and its day-number button both open the day; the
                 button keeps the action keyboard-reachable without nesting buttons. -->
            <div
                v-for="cell in weeks.flat()"
                :key="cell.key"
                class="flex min-h-0 min-w-0 cursor-pointer flex-col gap-1 overflow-hidden border-b border-l border-surface-100 p-1.5 transition-colors hover:bg-surface-50 [&:nth-child(7n+1)]:border-l-0"
                :class="{ 'bg-surface-50/50': !cell.inMonth }"
                @click="emit('cell-click', cell.date)"
            >
                <button
                    type="button"
                    class="flex size-6 shrink-0 cursor-pointer items-center justify-center self-start rounded-full text-xs font-semibold tabular-nums"
                    :class="
                        cell.isToday
                            ? 'bg-primary-500 text-white'
                            : cell.inMonth
                              ? 'text-surface-700'
                              : 'text-surface-400'
                    "
                    @click.stop="emit('cell-click', cell.date)"
                >
                    {{ cell.dayNum }}
                </button>

                <span class="flex min-h-0 flex-col gap-0.5 overflow-hidden">
                    <button
                        v-for="summary in cell.visible"
                        :key="summary.doctorId"
                        type="button"
                        class="flex cursor-pointer items-center gap-1 rounded px-1 py-0.5 text-xs hover:bg-surface-100"
                        @click.stop="
                            emit(
                                'summary-click',
                                summary.date,
                                summary.doctorId,
                            )
                        "
                    >
                        <span
                            class="size-2 shrink-0 rounded-full"
                            :style="{
                                backgroundColor: doctorColor(summary.doctorId),
                            }"
                            aria-hidden="true"
                        />
                        <span class="truncate text-surface-600">
                            {{ summary.doctorName }}
                        </span>
                        <span
                            class="ml-auto shrink-0 font-semibold text-surface-900"
                        >
                            {{ summary.count }}
                        </span>
                    </button>

                    <span
                        v-if="cell.overflow > 0"
                        class="px-1 text-[0.7rem] text-surface-400"
                    >
                        +{{ cell.overflow }}
                    </span>
                </span>
            </div>
        </div>
    </div>
</template>
