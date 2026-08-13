<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import {
    IconCalendarPlus,
    IconChevronLeft,
    IconChevronRight,
    IconLoader2,
} from '@tabler/icons-vue';
import { useElementSize, useNow } from '@vueuse/core';
import { addDays, startOfWeek } from 'date-fns';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AppointmentCancelDialog from '@/components/appointments/AppointmentCancelDialog.vue';
import ButtonLink from '@/components/ButtonLink.vue';
import AppointmentPopover from '@/components/calendar/AppointmentPopover.vue';
import CalendarMonthGrid from '@/components/calendar/CalendarMonthGrid.vue';
import CalendarTimeGrid from '@/components/calendar/CalendarTimeGrid.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordName from '@/components/RecordName.vue';
import { useAppointmentActions } from '@/composables/useAppointmentActions';
import { useCalendarEvents } from '@/composables/useCalendarEvents';
import { useCalendarNavigation } from '@/composables/useCalendarNavigation';
import { useCan } from '@/composables/useCan';
import { useDateTime } from '@/composables/useDateTime';
import { useTreatmentActions } from '@/composables/useTreatmentActions';
import AppLayout from '@/layouts/AppLayout.vue';
import { create as appointmentCreate } from '@/routes/appointments';
import type {
    CalendarColumn,
    CalendarDaySummary,
    CalendarEventDto,
    CalendarIndexProps,
} from '@/types/calendar';
import type { WeekDay } from '@/types/clinic';
import type { AppointmentStatus } from '@/types/enums';
import {
    appointmentStatusColor,
    DEFAULT_CALENDAR_STATUSES,
    MVP_APPOINTMENT_STATUSES,
} from '@/utils/appointmentStatus';
import { wallClockDate, wallClockMinutes } from '@/utils/calendarLayout';
import {
    formatWeekdayShort,
    parseDateString,
    toDateString,
} from '@/utils/datetime';
import { shouldFilterSelect } from '@/utils/selectFilter';

defineOptions({ layout: AppLayout });

const props = defineProps<CalendarIndexProps>();

const { t, locale } = useI18n();
const { can } = useCan();
const page = usePage();
const { parseUtc } = useDateTime();
const canViewAll = computed(() => can('appointments.viewAll'));

// A doctor with full visibility defaults to their own column; a non-doctor (owner/manager/
// reception) defaults to all doctors. Empty selection = every doctor the viewer may see.
// Without viewAll the filter is hidden and the server forces own.
const doctorFilter = ref<number[]>(
    canViewAll.value && props.ownDoctorId ? [props.ownDoctorId] : [],
);
const statuses = ref<AppointmentStatus[]>([...DEFAULT_CALENDAR_STATUSES]);

// Empty = the active clinic only. More than one branch turns the grid into a cross-branch month
// summary. Doctors are per-clinic, so the doctor filter and the per-doctor columns only make sense
// while the grid is showing the active clinic alone.
const branchFilter = ref<number[]>([]);
const multiBranch = computed(() => branchFilter.value.length > 1);
const activeClinicId = computed(() => page.props.activeClinic?.id ?? null);
const crossBranch = computed(
    () =>
        multiBranch.value ||
        (branchFilter.value.length === 1 &&
            branchFilter.value[0] !== activeClinicId.value),
);

// View/date state + the fetch range, header title, and prev/next/today stepping (pure nav math).
const { activeView, viewDate, range, currentTitle, goPrev, goNext, goToday } =
    useCalendarNavigation(props.defaultView);

const WEEK_ORDER: WeekDay[] = [
    'monday',
    'tuesday',
    'wednesday',
    'thursday',
    'friday',
    'saturday',
    'sunday',
];

function toMinutes(time: string): number {
    const [h, m] = time.split(':').map(Number);

    return h * 60 + m;
}

const openDays = computed(() =>
    WEEK_ORDER.map((day) => ({ day, hours: props.workingHours?.[day] })).filter(
        (
            entry,
        ): entry is {
            day: WeekDay;
            hours: {
                open: string;
                close: string;
                break: [string, string] | null;
            };
        } => Boolean(entry.hours && !('closed' in entry.hours)),
    ),
);

const workingFrom = computed(() =>
    openDays.value.length === 0
        ? 8 * 60
        : Math.min(...openDays.value.map((d) => toMinutes(d.hours.open))),
);
const workingTo = computed(() =>
    openDays.value.length === 0
        ? 20 * 60
        : Math.max(...openDays.value.map((d) => toMinutes(d.hours.close))),
);

// Appointments outside working hours (walk-ins, exceptions to the schedule) shouldn't be clipped:
// expand the time axis to the hour around the earliest/latest appointment. Leave blocks don't
// expand it (an all-day leave would otherwise force a 00:00–24:00 axis).
const eventBounds = computed(() => {
    let min = Infinity;
    let max = -Infinity;

    for (const a of data.value) {
        min = Math.min(min, wallClockMinutes(a.start));
        max = Math.max(max, wallClockMinutes(a.end));
    }

    return { min, max };
});

const timeFrom = computed(() =>
    data.value.length > 0 && eventBounds.value.min < workingFrom.value
        ? Math.floor(eventBounds.value.min / 60) * 60
        : workingFrom.value,
);
const timeTo = computed(() =>
    data.value.length > 0 && eventBounds.value.max > workingTo.value
        ? Math.ceil(eventBounds.value.max / 60) * 60
        : workingTo.value,
);

// Week/day place events on a single clinic's time axis (working hours, closed bands, now-line,
// doctor columns), all taken from the ACTIVE clinic. That axis is wrong for any cross-branch
// read, including a single branch that is not the active one, so month is the only view there.
watch(crossBranch, (cross) => {
    if (cross) {
        activeView.value = 'month';
    }
});

// `doctors` also carries the soft-deleted ones so their old appointments stay reachable, but they
// are never part of "everyone" — the empty-filter default matches the server's active-only scope.
const activeDoctors = computed(() =>
    props.doctors.filter((d) => !d.is_deleted),
);

// The doctors the grid renders: the selection, or every active one when nothing is selected.
const visibleDoctors = computed(() =>
    crossBranch.value
        ? []
        : doctorFilter.value.length
          ? props.doctors.filter((d) => doctorFilter.value.includes(d.id))
          : activeDoctors.value,
);

// A column mixing several doctors' leave has to name each block; a doctor column already has the
// name in its header.
const namedLeave = computed(
    () => canViewAll.value && visibleDoctors.value.length > 1,
);

// Per-doctor columns only when an all-access user views a single day across several doctors.
const schedulesActive = computed(
    () =>
        canViewAll.value &&
        activeView.value === 'day' &&
        visibleDoctors.value.length > 1,
);

// Stable per-doctor accent (cycled) — shared by the day-view column header dot and the month-view
// daily summary dot, so a doctor reads as the same colour across views. Branch chips cycle the
// same palette over the branch list.
const CHIP_PALETTE = [
    'var(--p-blue-400)',
    'var(--p-green-400)',
    'var(--p-purple-400)',
    'var(--p-orange-400)',
    'var(--p-pink-400)',
    'var(--p-teal-400)',
];
function paletteAt(index: number): string {
    return CHIP_PALETTE[(index < 0 ? 0 : index) % CHIP_PALETTE.length];
}
function doctorColor(doctorId: number): string {
    return paletteAt(props.doctors.findIndex((d) => d.id === doctorId));
}
// The month grid's chip dimension follows the mode: doctor normally, branch in multi-branch mode.
function chipColor(key: number): string {
    return multiBranch.value
        ? paletteAt(props.clinics.findIndex((c) => c.id === key))
        : doctorColor(key);
}

// Only a column that mixes doctors needs the per-chip accent; a per-doctor column names its doctor.
const mixedDoctorColors = computed(() =>
    canViewAll.value && visibleDoctors.value.length > 1
        ? Object.fromEntries(
              visibleDoctors.value.map((d) => [d.id, doctorColor(d.id)]),
          )
        : undefined,
);

const fetchParams = computed(() => ({
    start: toDateString(range.value.start),
    end: toDateString(range.value.end),
    // Doctors are per-clinic, so a doctor filter cannot reach another branch — the server ignores
    // it outside the active clinic and we keep the request honest about that.
    doctorIds: crossBranch.value ? [] : [...doctorFilter.value],
    clinicIds: [...branchFilter.value],
    statuses: statuses.value,
}));

const { state, data, exceptions, refresh } = useCalendarEvents(
    () => fetchParams.value,
);

// Live clock in the clinic timezone, ticking each minute — drives the now-line + today highlight.
const now = useNow({ interval: 60_000 });
const clinicNow = computed(() => parseUtc(now.value));
const todayKey = computed(() => toDateString(clinicNow.value));
const nowMinutes = computed(
    () => clinicNow.value.getHours() * 60 + clinicNow.value.getMinutes(),
);

// Stretch the time rows so the grid fills the body height (no inner scroll when it fits), down to a
// readable minimum per slot; the grid scrolls only when even the minimum would overflow.
const calendarBody = ref<HTMLElement>();
const { height: bodyHeight } = useElementSize(calendarBody);
// Column-header row (~44) + the grid's top/bottom scroll padding (~20) — kept out of the fillable
// area so the grid still fits without scroll when the working window is short.
const HEADER_RESERVE = 64;
const MIN_SLOT_PX = 26;
const minutesSpan = computed(() => Math.max(1, timeTo.value - timeFrom.value));
const pxPerMinute = computed(() => {
    const minPx = MIN_SLOT_PX / props.defaultSlotDuration;
    const available = bodyHeight.value - HEADER_RESERVE;

    if (available <= 0) {
        return minPx;
    }

    return Math.max(minPx, available / minutesSpan.value);
});

function eventsForDate(date: string): CalendarEventDto[] {
    return data.value.filter((a) => wallClockDate(a.start) === date);
}
function exceptionsForDate(date: string) {
    return exceptions.value.filter(
        (e) => wallClockDate(e.start) <= date && wallClockDate(e.end) >= date,
    );
}

// Greyed (non-interactive) before-open / lunch break / after-close bands for a column's weekday.
function closedBandsFor(date: Date) {
    const weekday = WEEK_ORDER[(date.getDay() + 6) % 7];
    const hours = props.workingHours?.[weekday];

    if (!hours) {
        return [];
    }

    if ('closed' in hours) {
        return [{ fromMin: timeFrom.value, toMin: timeTo.value }];
    }

    const bands: { fromMin: number; toMin: number; label?: string }[] = [];
    const open = toMinutes(hours.open);
    const close = toMinutes(hours.close);

    if (open > timeFrom.value) {
        bands.push({ fromMin: timeFrom.value, toMin: open });
    }

    if (hours.break) {
        bands.push({
            fromMin: toMinutes(hours.break[0]),
            toMin: toMinutes(hours.break[1]),
            label: t('calendar.break'),
        });
    }

    if (close < timeTo.value) {
        bands.push({ fromMin: close, toMin: timeTo.value });
    }

    return bands;
}

const gridColumns = computed<CalendarColumn[]>(() => {
    if (activeView.value === 'month') {
        return [];
    }

    if (activeView.value === 'week') {
        const start = startOfWeek(viewDate.value, { weekStartsOn: 1 });
        const cols: CalendarColumn[] = [];

        for (let i = 0; i < 7; i += 1) {
            const date = addDays(start, i);
            const weekday = WEEK_ORDER[(date.getDay() + 6) % 7];
            const hours = props.workingHours?.[weekday];

            // Fully-closed weekdays are dropped from the week view.
            if (hours && 'closed' in hours) {
                continue;
            }

            const ds = toDateString(date);
            cols.push({
                key: ds,
                date,
                label: formatWeekdayShort(date, locale.value),
                sublabel: String(date.getDate()),
                isToday: ds === todayKey.value,
                events: eventsForDate(ds),
                exceptions: exceptionsForDate(ds),
                closedBands: closedBandsFor(date),
                namedLeave: namedLeave.value,
                doctorColors: mixedDoctorColors.value,
            });
        }

        return cols;
    }

    const date = viewDate.value;
    const ds = toDateString(date);
    const dayEvents = eventsForDate(ds);
    const dayExceptions = exceptionsForDate(ds);

    if (schedulesActive.value) {
        return visibleDoctors.value.map((d) => ({
            key: `doc-${d.id}`,
            date,
            label: d.display_name,
            tintColor: doctorColor(d.id),
            isToday: ds === todayKey.value,
            events: dayEvents.filter((e) => e.doctor_id === d.id),
            exceptions: dayExceptions.filter((e) => e.doctor_id === d.id),
            closedBands: closedBandsFor(date),
        }));
    }

    return [
        {
            key: ds,
            date,
            label: '',
            isToday: ds === todayKey.value,
            events: dayEvents,
            exceptions: dayExceptions,
            closedBands: closedBandsFor(date),
            namedLeave: namedLeave.value,
            doctorColors: mixedDoctorColors.value,
        },
    ];
});

// Month view: ONE chip per dimension per day — the doctor normally, the branch when several are
// selected. Keyed by date for the grid cell lookup.
const summaryOrder = computed<number[]>(() =>
    multiBranch.value ? branchFilter.value : props.doctors.map((d) => d.id),
);

const summariesByDate = computed(() => {
    const byKey = new Map<string, CalendarDaySummary>();

    for (const a of data.value) {
        const date = wallClockDate(a.start);
        const dimension = multiBranch.value ? a.clinic_id : a.doctor_id;
        const label = multiBranch.value ? a.clinic_name : a.doctor_name;
        const key = `${date}-${dimension}`;
        const existing = byKey.get(key);

        if (existing) {
            existing.count += 1;
        } else {
            byKey.set(key, { date, key: dimension, label, count: 1 });
        }
    }

    const byDate = new Map<string, CalendarDaySummary[]>();

    for (const summary of byKey.values()) {
        const arr = byDate.get(summary.date) ?? [];
        arr.push(summary);
        byDate.set(summary.date, arr);
    }

    for (const arr of byDate.values()) {
        arr.sort(
            (a, b) =>
                summaryOrder.value.indexOf(a.key) -
                summaryOrder.value.indexOf(b.key),
        );
    }

    return byDate;
});

const doctorOptions = computed(() =>
    props.doctors.map((d) => ({
        label: d.display_name,
        value: d.id,
        deleted: d.is_deleted,
    })),
);
const statusOptions = computed(() =>
    MVP_APPOINTMENT_STATUSES.map((status) => ({
        label: t(`appointment.status.${status}`),
        value: status,
        // Same hue the chips use on the grid, so filter and calendar read as one legend.
        color: appointmentStatusColor(status),
    })),
);
const branchOptions = computed(() =>
    props.clinics.map((c) => ({ label: c.name, value: c.id })),
);
// Week/day are unavailable for any cross-branch read (see the crossBranch watcher).
const viewOptions = computed(() => [
    { label: t('calendar.views.month'), value: 'month' as const },
    {
        label: t('calendar.views.week'),
        value: 'week' as const,
        disabled: crossBranch.value,
    },
    {
        label: t('calendar.views.day'),
        value: 'day' as const,
        disabled: crossBranch.value,
    },
]);

const isLoading = computed(
    () => state.value === 'loading' || state.value === 'idle',
);
const isEmpty = computed(
    () => state.value === 'loaded' && data.value.length === 0,
);

function onMonthCellClick(date: Date): void {
    viewDate.value = date;

    // Same axis guard as onSummaryClick below: a bare cell click carries no branch/doctor
    // narrowing, so in cross-branch mode there is no single clinic to key a day view off of —
    // stay on month.
    if (!crossBranch.value) {
        activeView.value = 'day';
    }
}

// Month summary click: narrow to the clicked chip's dimension — the branch in multi-branch mode,
// the doctor otherwise — and land on that day.
function onSummaryClick(date: string, key: number): void {
    if (multiBranch.value) {
        branchFilter.value = [key];
    } else if (canViewAll.value) {
        doctorFilter.value = [key];
    }

    viewDate.value = parseDateString(date);

    // Only THEN open day view, and only if the narrowed selection lands on the active clinic's
    // axis. Reading crossBranch here (after the writes above) re-evaluates it against the new
    // branchFilter/doctorFilter, so a chip for another branch — or a doctor chip while a
    // non-active branch is still selected — correctly stays crossBranch and stays on month,
    // instead of opening a day view keyed to the wrong clinic's working hours.
    if (!crossBranch.value) {
        activeView.value = 'day';
    }
}

// Shared lifecycle actions — same gating the list uses, so the popover never drifts. Pair with the
// <AppointmentCancelDialog> mounted below (the optional-reason confirm group). Events come from JSON
// (not Inertia props), so refetch them on a successful cancel/delete.
const actions = useAppointmentActions(props.ownDoctorId, {
    onSuccess: refresh,
});
// Top-level ref so the template auto-unwraps it for the cancel dialog's v-model.
const { cancelReason } = actions;

// Start / resume treatment shares the same gating as the appointment list.
const treatmentActions = useTreatmentActions(props.ownDoctorId);

const popover = ref<InstanceType<typeof AppointmentPopover>>();

function onSelectEvent(
    domEvent: MouseEvent,
    appointment: CalendarEventDto,
): void {
    popover.value?.show(domEvent, appointment);
}
</script>

<template>
    <div class="flex viewport-content flex-col gap-6">
        <Head :title="t('calendar.title')" />

        <PageHeader
            class="shrink-0"
            :title="t('calendar.title')"
            :description="t('calendar.subtitle')"
            :breadcrumbs="[{ label: t('nav.calendar') }]"
        >
            <template #actions>
                <ButtonLink
                    :href="appointmentCreate().url"
                    :label="t('calendar.create')"
                >
                    <template #icon>
                        <IconCalendarPlus />
                    </template>
                </ButtonLink>
            </template>
        </PageHeader>

        <div class="flex max-h-240 min-h-0 flex-1 flex-col">
            <div
                class="flex shrink-0 flex-col gap-3 rounded-t-xl border border-surface-200 bg-surface-0 p-3 lg:flex-row lg:items-center lg:justify-between"
            >
                <div class="flex items-center gap-2">
                    <Button
                        type="button"
                        severity="secondary"
                        text
                        rounded
                        :aria-label="t('calendar.prev')"
                        @click="goPrev"
                    >
                        <IconChevronLeft class="size-5" />
                    </Button>
                    <Button
                        type="button"
                        severity="secondary"
                        outlined
                        size="small"
                        :label="t('calendar.today')"
                        @click="goToday"
                    />
                    <Button
                        type="button"
                        severity="secondary"
                        text
                        rounded
                        :aria-label="t('calendar.next')"
                        @click="goNext"
                    >
                        <IconChevronRight class="size-5" />
                    </Button>
                    <h2
                        class="ml-1 min-w-0 truncate text-base font-semibold text-surface-900"
                    >
                        {{ currentTitle }}
                    </h2>
                    <IconLoader2
                        v-if="isLoading"
                        class="size-4 shrink-0 animate-spin text-surface-400"
                        :aria-label="t('calendar.loading')"
                    />
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <MultiSelect
                        v-if="clinics.length > 1"
                        v-model="branchFilter"
                        :options="branchOptions"
                        option-label="label"
                        option-value="value"
                        :placeholder="t('calendar.all_branches')"
                        :max-selected-labels="1"
                        :selected-items-label="`{0} ${t('calendar.branch_filter')}`"
                        show-clear
                        :filter="shouldFilterSelect(branchOptions.length)"
                        :filter-placeholder="t('common.search')"
                        class="w-full sm:w-52"
                    />
                    <MultiSelect
                        v-if="!crossBranch && canViewAll && doctors.length > 0"
                        v-model="doctorFilter"
                        :options="doctorOptions"
                        option-label="label"
                        option-value="value"
                        :placeholder="t('calendar.all_doctors')"
                        :max-selected-labels="0"
                        :selected-items-label="`{0} ${t('common.doctor_selected_suffix')}`"
                        show-clear
                        :filter="shouldFilterSelect(doctorOptions.length)"
                        :filter-placeholder="t('common.search')"
                        class="w-full sm:w-52"
                    >
                        <template #option="{ option }">
                            <RecordName
                                :name="option.label"
                                :deleted="option.deleted"
                            />
                        </template>
                    </MultiSelect>
                    <MultiSelect
                        v-model="statuses"
                        :options="statusOptions"
                        option-label="label"
                        option-value="value"
                        :placeholder="t('calendar.filter_status')"
                        :max-selected-labels="MVP_APPOINTMENT_STATUSES.length"
                        class="w-full sm:w-56"
                    >
                        <template #option="{ option }">
                            <span class="flex items-center gap-2">
                                <span
                                    class="size-2.5 shrink-0 rounded-full"
                                    :style="{ backgroundColor: option.color }"
                                />
                                {{ option.label }}
                            </span>
                        </template>
                    </MultiSelect>
                    <span
                        v-tooltip.top="
                            multiBranch
                                ? t('calendar.multi_branch_month_only')
                                : undefined
                        "
                    >
                        <SelectButton
                            v-model="activeView"
                            :options="viewOptions"
                            option-label="label"
                            option-value="value"
                            option-disabled="disabled"
                            :allow-empty="false"
                            :aria-label="t('calendar.title')"
                        />
                    </span>
                </div>
            </div>

            <div
                ref="calendarBody"
                class="relative flex min-h-0 flex-1 flex-col overflow-hidden rounded-b-xl border border-t-0 border-surface-200 bg-surface-0"
            >
                <CalendarMonthGrid
                    v-if="activeView === 'month'"
                    :view-date="viewDate"
                    :summaries="summariesByDate"
                    :today-key="todayKey"
                    :chip-color="chipColor"
                    @cell-click="onMonthCellClick"
                    @summary-click="onSummaryClick"
                />
                <CalendarTimeGrid
                    v-else
                    :columns="gridColumns"
                    :from-minutes="timeFrom"
                    :to-minutes="timeTo"
                    :px-per-minute="pxPerMinute"
                    :now-minutes="nowMinutes"
                    @select="onSelectEvent"
                />

                <p
                    v-if="isEmpty"
                    class="pointer-events-none absolute inset-x-0 bottom-4 text-center text-sm text-surface-400"
                >
                    {{ t('calendar.no_events') }}
                </p>
            </div>
        </div>

        <AppointmentPopover
            ref="popover"
            :actions="actions"
            :treatment-actions="treatmentActions"
        />
        <AppointmentCancelDialog v-model="cancelReason" />
    </div>
</template>
