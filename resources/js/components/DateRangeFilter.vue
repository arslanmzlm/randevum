<script setup lang="ts">
import { IconChevronDown } from '@tabler/icons-vue';
import {
    endOfDay,
    endOfMonth,
    endOfWeek,
    endOfYear,
    startOfDay,
    startOfMonth,
    startOfWeek,
    startOfYear,
    subMonths,
} from 'date-fns';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    formatDateOnly,
    parseDateString,
    toDateString,
} from '@/utils/datetime';

// Presentational clinic-local date-range picker with quick presets and an all-time
// toggle. Emits the resolved window (Y-m-d strings, or nulls when all-time); the parent
// owns navigation, so this is reusable across the finance overview and Giderlerim.
const props = defineProps<{
    filters: { start: string | null; end: string | null; entire: boolean };
    loading?: boolean;
}>();

const emit = defineEmits<{
    change: [{ entire: boolean; start: string | null; end: string | null }];
}>();

const { t, locale } = useI18n();

const entire = ref(props.filters.entire);
const dateRange = ref<(Date | null)[]>([
    props.filters.start ? parseDateString(props.filters.start) : null,
    props.filters.end ? parseDateString(props.filters.end) : null,
]);

const presetMenu = ref();

const title = computed(() => {
    if (entire.value) {
        return t('date_filter.all_time');
    }

    const [start, end] = dateRange.value;

    if (!start) {
        return t('date_filter.range_title');
    }

    const startLabel = formatDateOnly(start, locale.value);

    return end
        ? `${startLabel} – ${formatDateOnly(end, locale.value)}`
        : startLabel;
});

// One watcher drives every change: presets and the custom picker only mutate state.
// A half-finished custom selection ([start, null]) is held until both ends are set.
watch(dateRange, () => {
    const [start, end] = dateRange.value ?? [];

    // A concrete range from the picker overrides all-time — the DatePicker's
    // v-model change doesn't touch `entire`, so reset it here.
    if (start && end) {
        entire.value = false;
        emit('change', {
            entire: false,
            start: toDateString(start),
            end: toDateString(end),
        });

        return;
    }

    if (entire.value) {
        emit('change', { entire: true, start: null, end: null });
    }
});

const now = (): Date => new Date();

const presetItems = computed(() => [
    {
        label: t('date_filter.presets_title'),
        items: [
            {
                label: t('date_filter.today'),
                command: () => setRange(startOfDay(now()), endOfDay(now())),
            },
            {
                label: t('date_filter.this_week'),
                command: () =>
                    setRange(
                        startOfWeek(now(), { weekStartsOn: 1 }),
                        endOfWeek(now(), { weekStartsOn: 1 }),
                    ),
            },
            {
                label: t('date_filter.this_month'),
                command: () => setRange(startOfMonth(now()), endOfMonth(now())),
            },
            {
                label: t('date_filter.last_month'),
                command: () => {
                    const month = subMonths(now(), 1);
                    setRange(startOfMonth(month), endOfMonth(month));
                },
            },
            {
                label: t('date_filter.this_year'),
                command: () => setRange(startOfYear(now()), endOfYear(now())),
            },
            {
                label: t('date_filter.all_time'),
                command: () => setEntire(),
            },
        ],
    },
]);

function setRange(start: Date, end: Date): void {
    entire.value = false;
    dateRange.value = [start, end];
}

function setEntire(): void {
    entire.value = true;
    dateRange.value = [null, null];
}

function togglePresets(event: Event): void {
    presetMenu.value.toggle(event);
}
</script>

<template>
    <div
        class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
    >
        <div class="flex flex-col gap-1">
            <h2 class="text-lg font-semibold text-surface-900">
                {{ t('date_filter.range_title') }}
            </h2>
            <p class="text-sm text-surface-500">{{ title }}</p>
        </div>

        <div class="flex items-center gap-2">
            <DatePicker
                v-model="dateRange"
                selection-mode="range"
                :number-of-months="2"
                :manual-input="false"
                date-format="dd.mm.yy"
                show-button-bar
                :placeholder="t('date_filter.range_placeholder')"
                class="w-full sm:w-64"
                :pt="{ panel: { class: 'daterange-panel-centered' } }"
            />

            <Button
                type="button"
                severity="secondary"
                outlined
                :loading="loading"
                :aria-label="t('date_filter.presets_title')"
                @click="togglePresets"
            >
                <template #icon>
                    <IconChevronDown class="size-4" />
                </template>
            </Button>
            <Menu ref="presetMenu" :model="presetItems" :popup="true" />
        </div>
    </div>
</template>
