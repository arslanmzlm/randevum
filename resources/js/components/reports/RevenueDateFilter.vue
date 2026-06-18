<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { IconChevronDown, IconRefresh } from '@tabler/icons-vue';
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
import { clearCache } from '@/actions/App/Modules/Billing/Http/Controllers/RevenueController';
import { revenue } from '@/routes/reports';
import type { RevenueFilters } from '@/types/revenue';
import {
    formatDateOnly,
    parseDateString,
    toDateString,
} from '@/utils/datetime';

const props = defineProps<{ filters: RevenueFilters }>();

const { t, locale } = useI18n();

const entire = ref(props.filters.entire);
const dateRange = ref<(Date | null)[]>([
    props.filters.start ? parseDateString(props.filters.start) : null,
    props.filters.end ? parseDateString(props.filters.end) : null,
]);

const loading = ref(false);
const presetMenu = ref();

const title = computed(() => {
    if (entire.value) {
        return t('revenue.all_time');
    }

    const [start, end] = dateRange.value;

    if (!start) {
        return t('revenue.range_title');
    }

    const startLabel = formatDateOnly(start, locale.value);

    return end
        ? `${startLabel} – ${formatDateOnly(end, locale.value)}`
        : startLabel;
});

function visit(query: Record<string, string | boolean>): void {
    router.get(revenue().url, query, {
        only: ['summary', 'range', 'filters', 'flash'],
        preserveState: true,
        preserveScroll: true,
        onStart: () => {
            loading.value = true;
        },
        onFinish: () => {
            loading.value = false;
        },
    });
}

// One watcher drives every change: presets and the custom picker only mutate state.
// A half-finished custom selection ([start, null]) is held until both ends are set.
watch(dateRange, () => {
    if (entire.value) {
        visit({ entire: true });

        return;
    }

    const [start, end] = dateRange.value;

    if (start && end) {
        visit({ start: toDateString(start), end: toDateString(end) });
    }
});

const now = (): Date => new Date();

const presetItems = computed(() => [
    {
        label: t('revenue.presets.title'),
        items: [
            {
                label: t('revenue.presets.today'),
                command: () => setRange(startOfDay(now()), endOfDay(now())),
            },
            {
                label: t('revenue.presets.this_week'),
                command: () =>
                    setRange(
                        startOfWeek(now(), { weekStartsOn: 1 }),
                        endOfWeek(now(), { weekStartsOn: 1 }),
                    ),
            },
            {
                label: t('revenue.presets.this_month'),
                command: () => setRange(startOfMonth(now()), endOfMonth(now())),
            },
            {
                label: t('revenue.presets.last_month'),
                command: () => {
                    const month = subMonths(now(), 1);
                    setRange(startOfMonth(month), endOfMonth(month));
                },
            },
            {
                label: t('revenue.presets.this_year'),
                command: () => setRange(startOfYear(now()), endOfYear(now())),
            },
            {
                label: t('revenue.presets.all_time'),
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

function clearReportCache(): void {
    router.post(
        clearCache().url,
        {},
        { preserveScroll: true, preserveState: false },
    );
}
</script>

<template>
    <div
        class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
    >
        <div class="flex flex-col gap-1">
            <h2 class="text-lg font-semibold text-surface-900">
                {{ t('revenue.range_title') }}
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
                :placeholder="t('revenue.filter_date_range')"
                class="w-full sm:w-64"
                :pt="{ panel: { class: 'daterange-panel-centered' } }"
            />

            <Button
                type="button"
                severity="secondary"
                outlined
                :loading="loading"
                :aria-label="t('revenue.presets.title')"
                @click="togglePresets"
            >
                <template #icon>
                    <IconChevronDown class="size-4" />
                </template>
            </Button>
            <Menu ref="presetMenu" :model="presetItems" :popup="true" />

            <Button
                type="button"
                severity="secondary"
                outlined
                :aria-label="t('revenue.refresh')"
                v-tooltip.bottom="t('revenue.refresh')"
                @click="clearReportCache"
            >
                <template #icon>
                    <IconRefresh class="size-4" />
                </template>
            </Button>
        </div>
    </div>
</template>
